<?php

namespace App\Services\Accounting\Reports;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\Accounting\SettlementService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared statement logic for a customer or a supplier.
 *
 * A customer statement and a supplier statement are the same operation with the
 * debit and credit sides swapped: documents on one side, money on the other, a
 * running balance, and an opening that carries everything before the window.
 * Writing the ordering, window and running-balance logic twice would be two
 * chances to get the same arithmetic subtly different, so it lives here and the
 * subclasses only say which documents are debits, which are credits, and which
 * direction a positive balance faces.
 *
 * The statement is derived from Phase 5 documents alone. Posted invoices/bills
 * are the debit/credit entries and posted receipts/payments are the other side.
 * Manual journals are deliberately excluded: a journal line has no customer_id or
 * supplier_id, so there is no honest way to attribute one to a counterparty, and
 * guessing would put a number on a statement that the ledger cannot trace back.
 * That omission is documented as a limitation of this phase.
 */
abstract class CounterpartyStatementReportService
{
    public function __construct(protected readonly SettlementService $settlements) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(
        Company $company,
        Customer|Supplier $counterparty,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): array {
        $entries = $this->debitEntries($company, $counterparty)
            ->concat($this->creditEntries($company, $counterparty))
            ->sortBy([
                ['date', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $normal = $this->normalDirection();

        $opening = Money::zero();

        foreach ($entries as $entry) {
            if ($from !== null && $entry['date']->lt($from)) {
                $opening = $opening->plus($this->signed($entry, $normal));
            }
        }

        $rows = [];
        $running = $opening;
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();

        foreach ($entries as $entry) {
            if ($from !== null && $entry['date']->lt($from)) {
                continue;
            }

            if ($to !== null && $entry['date']->gt($to)) {
                continue;
            }

            $amount = $entry['amount'];

            if ($entry['direction'] === 'debit') {
                $totalDebit = $totalDebit->plus($amount);
            } else {
                $totalCredit = $totalCredit->plus($amount);
            }

            $running = $running->plus($this->signed($entry, $normal));

            $rows[] = [
                'date' => $entry['date']->toDateString(),
                'type' => $entry['type'],
                'reference' => $entry['reference'],
                'description' => $entry['description'],
                'debit' => $entry['direction'] === 'debit' ? $amount->toDatabase() : '0.0000',
                'credit' => $entry['direction'] === 'credit' ? $amount->toDatabase() : '0.0000',
                'running_balance' => $running->toDatabase(),
            ];
        }

        /*
         * The closing balance is the final running balance, not a separate
         * debit-minus-credit figure. For a customer those coincide, but a
         * supplier statement is credit-normal: bills credit and payments debit,
         * so `debit - credit` would report a payable as a negative number even
         * though every running balance above it is positive. Taking the running
         * total keeps the closing consistent with the column the user reads and
         * with SettlementService::supplierPayable.
         */
        $closing = $running;

        return [
            $this->counterpartyKey() => $this->summary($counterparty),
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'opening_balance' => $opening->toDatabase(),
            'rows' => $rows,
            'totals' => [
                'debit' => $totalDebit->toDatabase(),
                'credit' => $totalCredit->toDatabase(),
            ],
            'closing_balance' => $closing->toDatabase(),
        ];
    }

    /**
     * The signed contribution of an entry to the running balance.
     *
     * @param  array{amount: Money, direction: string}  $entry
     */
    private function signed(array $entry, string $normal): Money
    {
        if ($entry['direction'] === $normal) {
            return $entry['amount'];
        }

        return $entry['amount']->negate();
    }

    /**
     * @return Collection<int, array{date: Carbon, id: int, direction: string, type: string, reference: string|null, description: string|null, amount: Money}>
     */
    abstract protected function debitEntries(Company $company, Customer|Supplier $counterparty): Collection;

    /**
     * @return Collection<int, array{date: Carbon, id: int, direction: string, type: string, reference: string|null, description: string|null, amount: Money}>
     */
    abstract protected function creditEntries(Company $company, Customer|Supplier $counterparty): Collection;

    /**
     * "customer" or "supplier", used as the response's identifying key.
     */
    abstract protected function counterpartyKey(): string;

    /**
     * Which side a positive running balance faces: `debit` for a customer
     * (they owe us), `credit` for a supplier (we owe them).
     */
    abstract protected function normalDirection(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function summary(Customer|Supplier $counterparty): array;
}
