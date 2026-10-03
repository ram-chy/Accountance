<?php

namespace App\Services\Accounting\Reports;

use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\CreditDebitNote;
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
 * The statement is derived from transactional documents only. Posted
 * invoices/bills are the debit/credit entries, posted receipts/payments are the
 * other side, and Phase 11's posted credit and debit notes are a third - see
 * noteEntries() below, which is the reason a customer's statement shows the credit
 * that reduced their balance instead of silently disagreeing with it.
 *
 * Manual journals are still deliberately excluded: a journal line has no
 * customer_id or supplier_id, so there is no honest way to attribute one to a
 * counterparty, and guessing would put a number on a statement that the ledger
 * cannot trace back. That omission remains a documented limitation.
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
            ->concat($this->noteEntries($company, $counterparty))
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
     * Posted credit and debit notes for this counterparty.
     *
     * Phase 11. A note is a real movement on the counterparty's account - it
     * moves money in the sense that matters to a statement, which is "what they owe
     * us", not "what has moved through a bank" - so omitting it would leave the
     * statement's closing balance disagreeing with SettlementService's, by exactly
     * the amount credited.
     *
     * WHICH SIDE A NOTE FALLS ON IS NOT THE SAME ANSWER FOR BOTH WORLDS
     *
     * The direction is derived from the statement's normal direction rather than
     * from the note's type, because the two statements have opposite normals:
     *
     *   customer (normal debit)  sales credit note     -> credit
     *   supplier (normal credit) purchase credit note  -> debit
     *
     * A credit note always reduces what the counterparty owes. On a customer
     * statement that is a credit; on a supplier statement - which is payable-
     * positive, so a bill is a credit - it is a debit. Reading the note's own
     * isCredit() as the statement direction would therefore be right for one
     * report and wrong for the other, and would double the payable of a bill that
     * had been credited by its supplier. Hence: a credit note sits on the side
     * OPPOSITE the normal one, and a debit note on the normal one.
     *
     * Only POSTED notes appear. A draft note has no journal and has adjusted
     * nothing, so a statement that showed one would carry a figure the ledger
     * cannot trace - the same exclusion receipts and invoices already get.
     *
     * @return Collection<int, array{date: Carbon, id: int, direction: string, type: string, reference: string|null, description: string|null, amount: Money}>
     */
    protected function noteEntries(Company $company, Customer|Supplier $counterparty): Collection
    {
        $normal = $this->normalDirection();
        $opposite = $normal === 'debit' ? 'credit' : 'debit';

        $column = $counterparty instanceof Customer ? 'customer_id' : 'supplier_id';

        return CreditDebitNote::query()
            ->where('company_id', $company->getKey())
            ->where($column, $counterparty->getKey())
            ->where('status', TransactionStatus::Posted->value)
            ->orderBy('note_date')
            ->orderBy('id')
            ->get()
            ->map(fn (CreditDebitNote $note): array => [
                'date' => Carbon::parse($note->note_date),

                /*
                 * The note's own id, not a prefixed one. It has to sort correctly
                 * against invoices, bills, receipts and payments - all of which
                 * contribute their own primary key to the ordering - and only the
                 * raw key is comparable. A note dated the same day as a receipt is
                 * therefore ordered against it by id, which is arbitrary but stable
                 * and reproducible; the alternative, a separate sort key, would
                 * reorder a customer's own history on every statement run.
                 */
                'id' => $note->getKey(),
                'direction' => $note->note_type->isCredit() ? $opposite : $normal,
                'type' => $note->note_type->isCredit() ? 'credit_note' : 'debit_note',

                /*
                 * The counterparty's own reference where there is one - the returns
                 * note number they quoted - and the internal note number otherwise,
                 * so the column is never blank and the row is always traceable back
                 * to the document.
                 */
                'reference' => $note->reference ?: $note->note_number,

                /*
                 * The reason, for the same reason invoices show their notes here:
                 * it is the one part of the entry not derivable from the amount and
                 * the document it adjusts, so it is what a reader needs in order to
                 * recognise the row without opening it.
                 */
                'description' => $note->reason,
                'amount' => Money::of($note->grand_total),
            ]);
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
