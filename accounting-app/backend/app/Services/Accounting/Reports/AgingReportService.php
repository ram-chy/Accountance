<?php

namespace App\Services\Accounting\Reports;

use App\Models\Company;
use App\Services\Accounting\Reports\Concerns\AgesDocuments;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Buckets outstanding documents by age, by counterparty.
 *
 * Both aging reports start from the corresponding receivables/payables report
 * rather than re-querying. They are the same set of outstanding documents
 * grouped differently, and deriving them from one source means the two can never
 * disagree about which documents are outstanding or by how much - an aging total
 * that did not equal the receivables total would be a contradiction visible to
 * any user.
 *
 * The document set is already draft-free and posted-only because the reports it
 * builds on are.
 */
abstract class AgingReportService
{
    use AgesDocuments;

    /**
     * @return array<string, mixed>
     */
    public function generate(Company $company, ?int $counterpartyId = null, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();

        $rows = $this->outstandingRows($company, $counterpartyId, $asOf);

        return $this->bucketed($company, $rows, $asOf);
    }

    /**
     * The outstanding document rows, in the shape Receivables/Payables returns.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract protected function outstandingRows(Company $company, ?int $counterpartyId, Carbon $asOf): array;

    /**
     * The key on each row that identifies the counterparty (`customer`/`supplier`).
     */
    abstract protected function counterpartyKey(): string;

    /**
     * The company base currency for the disclosure block (Phase 14 §21.1).
     *
     * The aging buckets are sums of `balance_due` figures that are each in their
     * own document's currency. The disclosure says what the report's currency
     * meter is so a bucket that mixes one foreign row into a base total is
     * visible as a mixed sum rather than mistaken for a ledger balance.
     *
     * @return array{code: string|null, name: string|null, symbol: string|null, decimals: int|null}
     */
    private function baseCurrency(Company $company): array
    {
        $currency = $company->currency;

        return [
            'code' => $currency?->code,
            'name' => $currency?->name,
            'symbol' => $currency?->symbol,
            'decimals' => $currency?->decimal_precision,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function bucketed(Company $company, array $rows, Carbon $asOf): array
    {
        $buckets = $this->agingBuckets();
        $keys = array_column($buckets, 'key');

        $bucketTotals = array_fill_keys($keys, Money::zero());
        $byCounterparty = [];
        $grandTotal = Money::zero();

        foreach ($rows as $row) {
            $bucketKey = $this->bucketKeyFor((int) $row['days_past_due']);
            $amount = Money::of($row['balance_due']);
            $counterparty = $row[$this->counterpartyKey()];
            $id = $counterparty['id'];

            if (! isset($byCounterparty[$id])) {
                $byCounterparty[$id] = [
                    'counterparty' => $counterparty,
                    'buckets' => array_fill_keys($keys, Money::zero()),
                    'total' => Money::zero(),
                ];
            }

            if ($bucketKey !== null) {
                $byCounterparty[$id]['buckets'][$bucketKey] = $byCounterparty[$id]['buckets'][$bucketKey]->plus($amount);
                $bucketTotals[$bucketKey] = $bucketTotals[$bucketKey]->plus($amount);
            }

            $byCounterparty[$id]['total'] = $byCounterparty[$id]['total']->plus($amount);
            $grandTotal = $grandTotal->plus($amount);
        }

        usort($byCounterparty, static function (array $a, array $b): int {
            return strcmp((string) $a['counterparty']['name'], (string) $b['counterparty']['name']);
        });

        return [
            'as_of' => $asOf->toDateString(),
            'base_currency' => $this->baseCurrency($company),
            'buckets' => array_map(fn (array $bucket): array => [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'min' => $bucket['min'],
                'max' => $bucket['max'],
                'total' => $bucketTotals[$bucket['key']]->toDatabase(),
            ], $buckets),
            'rows' => array_map(function (array $entry) use ($keys): array {
                return [
                    'counterparty' => $entry['counterparty'],
                    'buckets' => array_map(
                        fn (string $key): string => $entry['buckets'][$key]->toDatabase(),
                        array_combine($keys, $keys)
                    ),
                    'total' => $entry['total']->toDatabase(),
                ];
            }, array_values($byCounterparty)),
            'totals' => [
                'by_bucket' => array_map(
                    fn (string $key): string => $bucketTotals[$key]->toDatabase(),
                    array_combine($keys, $keys)
                ),
                'total' => $grandTotal->toDatabase(),
            ],
        ];
    }
}
