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
            ->with('supplier')
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

            $rows[] = [
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
        }

        return [
            'as_of' => $asOf->toDateString(),
            'rows' => $rows,
            'count' => count($rows),
            'totals' => [
                'grand_total' => $totalGrand->toDatabase(),
                'paid_total' => $totalPaid->toDatabase(),
                'balance_due' => $totalDue->toDatabase(),
            ],
        ];
    }
}
