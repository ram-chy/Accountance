<?php

namespace App\Services\Accounting\Reports;

use App\Models\Company;
use App\Models\PurchaseBill;
use App\Services\Accounting\Reports\Concerns\AgesDocuments;
use App\Services\Accounting\SettlementService;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Outstanding payables: every posted bill that still owes money.
 *
 * The mirror of the receivables report. `as_of` bounds which bills appear and
 * drives days past due; payment sums are current, not historical, for the same
 * reason stated on ReceivablesReportService.
 */
class PayablesReportService
{
    use AgesDocuments;

    public function __construct(private readonly SettlementService $settlements) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(Company $company, ?int $supplierId = null, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();

        $bills = PurchaseBill::query()
            ->where('company_id', $company->getKey())
            ->withOutstandingBalance()
            ->with(['supplier', 'currency'])
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->whereDate('bill_date', '<=', $asOf->toDateString())
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $this->settlements->attachFiguresForBills($bills);

        $rows = [];
        $totalGrand = Money::zero();
        $totalPaid = Money::zero();
        $totalDue = Money::zero();

        foreach ($bills as $bill) {
            $grand = $bill->grandTotalAmount();
            $paid = $bill->paidTotalAmount();
            $due = $bill->balanceDueAmount();

            $totalGrand = $totalGrand->plus($grand);
            $totalPaid = $totalPaid->plus($paid);
            $totalDue = $totalDue->plus($due);

            /*
             * Phase 14 §21: the amounts on a row are in the bill's own currency.
             * For a foreign bill that is not the base currency, so the row says
             * which currency it is and what one unit of it was worth, and the base
             * grand total (stored at posting, never recomputed) lets the balance be
             * read alongside the ledger without re-converting it today.
             */
            $row = [
                'bill_id' => $bill->getKey(),
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date?->toDateString(),
                'due_date' => $bill->due_date?->toDateString(),
                'supplier' => [
                    'id' => $bill->supplier?->getKey(),
                    'code' => $bill->supplier?->supplier_code,
                    'name' => $bill->supplier?->name,
                ],
                'grand_total' => $grand->toDatabase(),
                'paid_total' => $paid->toDatabase(),
                'balance_due' => $due->toDatabase(),
                'days_past_due' => $this->daysPastDue($bill->due_date, $asOf),
            ];

            if ($bill->isForeignCurrency()) {
                $row['currency_code'] = $bill->currency?->code;
                $row['exchange_rate'] = $bill->exchange_rate;
                $row['base_grand_total'] = $bill->baseGrandTotal()->toDatabase();
            }

            $rows[] = $row;
        }

        return [
            'as_of' => $asOf->toDateString(),
            'base_currency' => $this->baseCurrency($company),
            'rows' => $rows,
            'count' => count($rows),
            'totals' => [
                'grand_total' => $totalGrand->toDatabase(),
                'paid_total' => $totalPaid->toDatabase(),
                'balance_due' => $totalDue->toDatabase(),
            ],
        ];
    }

    /**
     * The company base currency for the disclosure block (Phase 14 §21.1).
     *
     * The mirror of ReceivablesReportService::baseCurrency().
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
}
