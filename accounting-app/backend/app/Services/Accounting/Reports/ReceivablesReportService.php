<?php

namespace App\Services\Accounting\Reports;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\Accounting\Reports\Concerns\AgesDocuments;
use App\Services\Accounting\SettlementService;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Outstanding receivables: every posted invoice that still owes money.
 *
 * The candidate set comes from `scopeWithOutstandingBalance`, which filters
 * drafts out and keeps only invoices whose grand total exceeds their posted
 * allocations. That scope is a correlated subquery per row rather than a stored
 * `paid_total` column, and it is reused rather than re-expressed here: a second
 * definition of "outstanding" would eventually disagree with the one the
 * settlement writes use.
 *
 * Figures are attached in bulk via SettlementService so listing n invoices is
 * one aggregate query, not n.
 *
 * `as_of` bounds which invoices are old enough to appear and computes days past
 * due. It does not time-scope the payment sums - settlement is computed from all
 * posted receipts - which is a deliberate limitation of this phase and is
 * restated in the Phase 6 report; a historical "as of last March the customer
 * owed X" figure would need per-date allocation history this schema does not
 * keep.
 */
class ReceivablesReportService
{
    use AgesDocuments;

    public function __construct(private readonly SettlementService $settlements) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(Company $company, ?int $customerId = null, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();

        $invoices = SalesInvoice::query()
            ->where('company_id', $company->getKey())
            ->withOutstandingBalance()
            ->with(['customer', 'currency'])
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId))
            ->whereDate('invoice_date', '<=', $asOf->toDateString())
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $this->settlements->attachFiguresForInvoices($invoices);

        $rows = [];
        $totalGrand = Money::zero();
        $totalPaid = Money::zero();
        $totalDue = Money::zero();

        foreach ($invoices as $invoice) {
            $grand = $invoice->grandTotalAmount();
            $paid = $invoice->paidTotalAmount();
            $due = $invoice->balanceDueAmount();

            $totalGrand = $totalGrand->plus($grand);
            $totalPaid = $totalPaid->plus($paid);
            $totalDue = $totalDue->plus($due);

            /*
             * Phase 14 §21: the amounts on a row are in the invoice's own currency.
             * For a foreign invoice that is not the base currency, so the row says
             * which currency it is and what one unit of it was worth, and the base
             * grand total (stored at posting, never recomputed) lets the balance be
             * read alongside the ledger without re-converting it today.
             */
            $row = [
                'invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'customer' => [
                    'id' => $invoice->customer?->getKey(),
                    'code' => $invoice->customer?->customer_code,
                    'name' => $invoice->customer?->name,
                ],
                'grand_total' => $grand->toDatabase(),
                'paid_total' => $paid->toDatabase(),
                'balance_due' => $due->toDatabase(),
                'days_past_due' => $this->daysPastDue($invoice->due_date, $asOf),
            ];

            if ($invoice->isForeignCurrency()) {
                $row['currency_code'] = $invoice->currency?->code;
                $row['exchange_rate'] = $invoice->exchange_rate;
                $row['base_grand_total'] = $invoice->baseGrandTotal()->toDatabase();
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
     * Outstanding invoices can be raised in different currencies, and a single
     * "balance_due" total that mixed them would be a number no currency could
     * settle - so the block says what the report's own currency meter is, and a
     * foreign row's currency_code is what to compare it against.
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
