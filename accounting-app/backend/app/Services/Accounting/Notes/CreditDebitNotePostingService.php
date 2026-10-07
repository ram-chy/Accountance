<?php

namespace App\Services\Accounting\Notes;

use App\Enums\JournalSource;
use App\Enums\TransactionStatus;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\CreditDebitNote;
use App\Models\CreditDebitNoteLine;
use App\Models\JournalLine;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Accounting\Currency\DocumentCurrencyService;
use App\Services\Accounting\Currency\TransactionCurrency;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\SettlementService;
use App\Services\Accounting\TransactionAccountResolver;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posting a credit/debit note: the bridge from the adjustment to the ledger.
 *
 * THE ENTRY, IN ALL FOUR CASES
 *
 * There are four note types but only two account pairs - the sales note moves the
 * receivable against revenue, the purchase note moves the payable against expense -
 * and within each pair a credit and a debit are the same entry reversed. So the
 * whole matrix is decided by two booleans rather than a four-way branch, which is
 * what makes it checkable by reading two lines instead of four blocks:
 *
 *   Sales credit note    Dr Revenue, Dr Tax Payable     Cr Accounts Receivable
 *   Sales debit note     Dr Accounts Receivable          Cr Revenue, Cr Tax Payable
 *   Purchase credit note Dr Accounts Payable             Cr Expense, Cr Input Tax
 *   Purchase debit note  Dr Expense, Dr Input Tax        Cr Accounts Payable
 *
 * Net figures are booked per distinct line account rather than as a single total,
 * for the reason SalesInvoicePostingService does the same: the ledger should be
 * able to answer "how much revenue did this note reverse?" without a report
 * re-reading the document.
 *
 * THE SOURCE DOCUMENT IS LOCKED FIRST, AND THAT IS THE WHOLE POINT
 *
 * The adjustment limit is checked here as well as at draft time, and this is the
 * check that makes it true. Two notes against one invoice can both be drafted
 * against the same remaining amount - both are valid drafts, since neither has
 * touched the ledger - and then both posted. Without a lock the first
 * transaction's sum and the second's are the same pre-commit read, both notes
 * pass, and the invoice ends up credited by more than it was worth with no
 * imbalance anywhere to reveal it.
 *
 * So the source row is locked FOR UPDATE before the note row, the sum is taken
 * from the committed state, and the second transaction blocks until the first has
 * either committed (and then fails, because the amount no longer fits) or rolled
 * back. Source-before-note is the order every write path in this module uses, and
 * keeping it consistent is what stops two notes on one document deadlocking against
 * each other.
 *
 * AN INACTIVE COUNTERPARTY DOES NOT BLOCK A NOTE
 *
 * SalesInvoicePostingService and PurchaseBillPostingService both refuse to post for
 * a deactivated counterparty. That rule is NOT copied here, and the difference is
 * deliberate: an invoice CREATES a new receivable, so refusing it merely asks the
 * user to reactivate the customer first and changes no accounting. A credit note
 * REDUCES an existing one. Refusing it would mean an invoice that was invoiced in
 * full, paid in full, and then partly returned could not be credited back because
 * the customer happened to be deactivated in the meantime - the receivable would be
 * left overstated with no available way to correct it.
 *
 * The receivable/payable account is still resolved and must still be active, because
 * the entry has to be posted somewhere. So the note is refused only when it could
 * not be booked, never merely because the party is inactive.
 */
class CreditDebitNotePostingService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly JournalPostingService $posting,
        private readonly TransactionAccountResolver $accounts,
        private readonly CreditDebitNoteAdjustmentService $adjustments,
        private readonly SettlementService $settlement,
        private readonly DocumentCurrencyService $currencies,
    ) {}

    /**
     * Post a draft note and its accounting entry, atomically.
     *
     * @throws ValidationException
     * @throws ConflictException when the note is already posted
     */
    public function post(CreditDebitNote $note, User $actor): CreditDebitNote
    {
        return DB::transaction(function () use ($note, $actor) {
            /*
             * 1. Lock the SOURCE document, before the note and before anything is
             *    decided. See the class docblock for why the order and the lock are
             *    both load-bearing.
             */
            $source = $this->adjustments->lockSource($this->adjustments->sourceFor($note));

            // 2. Lock the note, so two concurrent posts cannot interleave.
            $fresh = CreditDebitNote::query()
                ->whereKey($note->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Conflict rather than validation, matching the invoice and bill
             * posting services: a second post of an already-posted note is a caller
             * whose request was valid and lost a race, not a malformed request.
             */
            if ($fresh->status->isPosted()) {
                throw new ConflictException(
                    message: 'This note is already posted and cannot be posted again.',
                    errors: [
                        'note' => ['This note is already posted and cannot be posted again.'],
                    ],
                );
            }

            /*
             * The source cannot have changed - updateDraft has no rule for it and
             * the service ignores the fields - but the locked row is compared with
             * the note anyway. If that invariant is ever broken by a future path,
             * the limit below would be measured against the wrong document, and a
             * check that turns that into a 422 costs less than one that silently
             * books an adjustment against an unrelated invoice.
             */
            $this->assertSameSource($fresh, $source);

            /*
             * 3. The counterparty, for the receivable/payable account. Its existence
             *    is required; its active flag deliberately is not. See the class
             *    docblock.
             */
            $counterAccount = $fresh->note_type->isSales()
                ? $this->receivableFor($fresh)
                : $this->payableFor($fresh);

            // 4. Lines, and their accounts.
            $lines = $fresh->lines()->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'This note has no lines and cannot produce a balanced entry.',
                ]);
            }

            $itemByAccount = $this->itemByAccount($fresh, $lines);

            /*
             * 5. Totals, recomputed from the persisted lines rather than trusted
             *    from the note row, for the reason SalesInvoicePostingService does
             *    the same: a stale total must not produce a journal that disagrees
             *    with the lines it is supposedly derived from.
             */
            $totals = $this->recalculateTotals($lines);

            $grandTotal = Money::of($totals['grand_total']);

            if (! $grandTotal->isPositive()) {
                throw ValidationException::withMessages([
                    'grand_total' => 'A note must total more than zero to be posted.',
                ]);
            }

            /*
             * 6. THE ENFORCEMENT POINT. Re-checked here, from the committed state
             *    of the source document, under the lock taken in step 1.
             *
             *    Note the note's own amount is the server-recalculated grand total
             *    and the source's is the persisted invoice total: neither is ever
             *    taken from the request. A client that could name its own limit
             *    could name a larger one.
             */
            $net = $fresh->note_type->isSales()
                ? $this->adjustments->netAdjustmentForInvoice($source->getKey())
                : $this->adjustments->netAdjustmentForBill($source->getKey());

            $this->adjustments->assertWithinLimit(
                $fresh->note_type,
                $source instanceof SalesInvoice ? $source->invoice_number : $source->bill_number,
                $grandTotal,
                $net,
                Money::of($source->grand_total),
            );

            // 7. Per-line quantities, same reasoning, same lock.
            $this->reassertLineQuantities($fresh, $lines);

            /*
             * 8. The tax account, asked about the recomputed tax total so a stale
             *    column can neither lose the tax leg nor demand an account the
             *    entry does not use. tax() for a sales note (a LIABILITY account,
             *    where output tax owed lives) and inputTax() for a purchase note (an
             *    ASSET, where recovered input tax lives) - the same split
             *    PurchaseBillPostingService makes.
             */
            $taxAccount = $this->resolveTaxAccount($fresh, $totals['tax_total']);

            $sourceNumber = $source instanceof SalesInvoice
                ? $source->invoice_number
                : $source->bill_number;

            /*
             * The note's currency context, resolved here rather than read off the
             * draft. See SalesInvoicePostingService for why: the draft's rate is a
             * preview of a mutable rate table, and this is the last moment the note
             * can be priced. One resolved rate reaches the header, the document lines
             * and every journal line.
             */
            $transaction = $this->currencies->resolve(
                $fresh->company,
                $fresh->currency_id,
                $fresh->note_date->toDateString(),
                'currency_id',
            );

            // 9. Create the draft journal, then 10. post it through the single
            //    existing writer. Neither this service nor any controller writes
            //    journals.status or journal_lines.
            $journal = $this->journals->createDraft(
                $fresh->company,
                $actor,
                [
                    'journal_date' => $fresh->note_date->toDateString(),

                    /*
                     * Names the kind, the note and the document being adjusted, so a
                     * ledger reader can tell a sales credit note from a purchase
                     * debit note without opening the note - which matters because a
                     * ledger entry is often read years later with only its
                     * description to go on. The free-text reason is NOT copied in
                     * here: it is unbounded, and a journal description is a fixed
                     * column. It is on the note, and the note is one link away.
                     */
                    'description' => sprintf(
                        '%s %s against %s',
                        $fresh->note_type->label(),
                        $fresh->note_number,
                        $sourceNumber
                    ),

                    /*
                     * The counterparty's own reference is preferred here where there
                     * is one - a supplier's credit note number or a customer's
                     * returns note number is what an auditor searching the ledger
                     * will type - and the note's own number is the fallback, so the
                     * column is never empty.
                     */
                    'reference' => $fresh->reference ?: $fresh->note_number,
                    'source_type' => JournalSource::CreditDebitNote->value,

                    /*
                     * source_id is the NOTE's id, not the source document's. The
                     * ledger-side pointer has to resolve to the note: that is the
                     * document the entry belongs to, and the note is the thing whose
                     * amount moved. The adjusted document is already reachable from
                     * the note, so recording it here as well would be a second
                     * answer to a question that already has one.
                     */
                    'source_id' => $fresh->getKey(),
                    'lines' => $this->journalLines($transaction, $fresh, $counterAccount, $itemByAccount, $taxAccount, $totals),
                ],
            );

            $this->posting->post($journal, $actor, 'note_date');

            /*
             * The link and the status, in one statement so there is no moment at
             * which the note is POSTED without its journal - the same single-write
             * reasoning SalesInvoicePostingService uses, and the reason the schema's
             * posted_fields check can be partial without being weak.
             */
            /*
             * The note's base figures, read back out of the journal rather than
             * converted a second time. The counterparty leg carries the whole grand
             * total on its own, so its base amount IS the note's base grand total;
             * reading it rather than recomputing it is what keeps the note and its own
             * entry from ever being able to say different things.
             */
            $booked = $journal->lines()->get();

            $baseGrandTotal = $this->currencies->baseAmountBooked($booked, (int) $counterAccount->getKey());

            $baseTaxTotal = $taxAccount === null
                ? Money::zero()
                : $this->currencies->baseAmountBooked($booked, (int) $taxAccount->getKey());

            $this->writeBaseTaxAmounts($fresh, $transaction, $booked, $taxAccount);

            $fresh->forceFill([
                'status' => TransactionStatus::Posted->value,
                'journal_id' => $journal->getKey(),
                'posted_by' => $actor->getKey(),
                'posted_at' => now(),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'currency_id' => $transaction->currency?->getKey(),
                'exchange_rate' => $transaction->rateToPersist(),
                'base_grand_total' => $baseGrandTotal?->toDatabase(),
                'base_tax_total' => $baseTaxTotal->toDatabase(),
            ])->save();

            /*
             * 11. The source document's settlement status, recomputed - inside this
             *     transaction, and only now that the note is POSTED and so is part of
             *     the sum the status is derived from.
             *
             *     Without this the status column goes stale, and stale in the worst
             *     possible direction: an invoice paid in full and then credited would
             *     keep saying PAID while the balance_due computed from the same
             *     figures beside it said 100.00. Two keys in one response, one
             *     derived and one stored, disagreeing - the exact incoherence this
             *     phase exists to end.
             *
             *     Only the status column is written. The document's amounts, lines
             *     and journal are untouched: a credit note adjusts what is owed, it
             *     does not edit the invoice it adjusts. That is why this is a
             *     refreshInvoiceStatus/refreshBillStatus call and not anything
             *     wider - the same one-line write a receipt posting makes.
             */
            $source = $fresh->note_type->isSales()
                ? $this->settlement->refreshInvoiceStatus($source)
                : $this->settlement->refreshBillStatus($source);

            return $fresh->refresh();
        });
    }

    /**
     * Net revenue (sales) or expense (purchase) per distinct line account.
     *
     * Net rather than line_total: the tax portion of a line belongs to the tax
     * account, and booking it against revenue or expense would inflate the account
     * by the amount of tax reversed.
     *
     * @param  Collection<int, CreditDebitNoteLine>  $lines
     * @return array<int, Money> account id => net amount
     */
    private function itemByAccount(CreditDebitNote $note, Collection $lines): array
    {
        $role = $note->note_type->isSales() ? 'revenue_account_id' : 'expense_account_id';

        $totals = [];

        foreach ($lines as $line) {
            $net = Money::of($line->line_total)->minus(Money::of($line->tax_amount));

            $totals[$line->account_id] = ($totals[$line->account_id] ?? Money::zero())->plus($net);
        }

        /*
         * Revalidated here rather than trusted from draft time: an account that was
         * active when the note was saved may since have been deactivated, and this
         * entry is being made now. resolveAll() rather than resolveMany() because a
         * keyed role => id map cannot hold many accounts under one role - it would
         * validate only the last and quietly let the others through.
         */
        $this->accounts->resolveAll($note->company, $role, array_keys($totals));

        return $totals;
    }

    /**
     * Describe the journal entry.
     *
     * The direction of every leg comes from two booleans:
     *
     *   counterIsDebit - does the receivable/payable take a debit?
     *   itemIsDebit    - does revenue/expense (and tax) take a debit?
     *
     * and they are always opposites, because the entry is a two-sided movement:
     * whatever the counterparty side does, the other side does the reverse. Reading
     * that from the two booleans rather than from four explicit blocks is what
     * guarantees a new type cannot be added with one leg silently missing - there
     * is nowhere to put one.
     *
     * @param  array<int, Money>  $itemByAccount
     * @return array<int, array<string, mixed>>
     */
    private function journalLines(
        TransactionCurrency $transaction,
        CreditDebitNote $note,
        Account $counterAccount,
        array $itemByAccount,
        ?Account $taxAccount,
        array $totals,
    ): array {
        $type = $note->note_type;

        /*
         * A sales credit note credits the receivable; a purchase credit note
         * debits it. That single asymmetry is the whole of the sales/purchase
         * difference - "reduce what we are owed" is a credit for a customer and a
         * debit for a supplier, because the two sit on opposite sides of the
         * balance sheet. Everything else follows from it.
         */
        $counterIsDebit = $type->isSales() ? $type->isDebit() : $type->isCredit();

        $itemIsDebit = ! $counterIsDebit;

        $grandTotal = Money::of($totals['grand_total']);

        $lines = [$this->currencies->journalLine(
            $transaction,
            (int) $counterAccount->getKey(),
            $this->counterDescription($note),
            $grandTotal,
            $counterIsDebit,
        )];

        $itemDescription = $type->isSales() ? 'Sales revenue' : 'Purchased expense';

        foreach ($itemByAccount as $accountId => $amount) {
            $lines[] = $this->currencies->journalLine(
                $transaction,
                (int) $accountId,
                $itemDescription,
                $amount,
                $itemIsDebit,
            );
        }

        if ($taxAccount !== null) {
            $lines[] = $this->currencies->journalLine(
                $transaction,
                (int) $taxAccount->getKey(),
                $type->isSales() ? 'Tax payable' : 'Input tax',
                Money::of($totals['tax_total']),
                $itemIsDebit,
            );
        }

        return $lines;
    }

    /**
     * Record each note line's tax in base currency, from the posted journal.
     *
     * The distribution rule - every line but the last converted at the note's rate,
     * the last taking the remainder - is the one SalesInvoicePostingService applies,
     * for the same structural reason: a journal line holds one foreign amount and
     * one rate, so the tax leg is a single conversion of the note's tax total, and
     * the per-line figures that must add up to it cannot each be an independent
     * conversion.
     *
     * @param  Collection<int, JournalLine>  $booked
     */
    private function writeBaseTaxAmounts(
        CreditDebitNote $note,
        TransactionCurrency $transaction,
        Collection $booked,
        ?Account $taxAccount
    ): void {
        $bookedTax = $taxAccount === null
            ? null
            : $this->currencies->baseAmountBooked($booked, (int) $taxAccount->getKey());

        if ($bookedTax === null) {
            return;
        }

        $taxable = $note->lines()
            ->where('tax_amount', '>', 0)
            ->orderBy('line_number')
            ->get();

        if ($taxable->isEmpty()) {
            return;
        }

        $remaining = $bookedTax;
        $lastIndex = $taxable->count() - 1;

        foreach ($taxable as $position => $line) {
            $share = $position === $lastIndex
                ? $remaining
                : $this->currencies->toBase(Money::of($line->tax_amount), $transaction);

            if ($share->greaterThan($remaining)) {
                $share = $remaining;
            }

            $line->forceFill(['base_tax_amount' => $share->toDatabase()])->save();

            $remaining = $remaining->minus($share);
        }
    }

    /**
     * The receivable or payable leg's own account, resolved from the counterparty.
     *
     * The note stores the counterparty id but not their receivable/payable account:
     * the account is a property of the party, it is read from them at posting time
     * rather than copied onto the note when it was drafted, and that is the same
     * reason SalesInvoicePostingService reads it from the customer rather than from
     * the invoice.
     *
     * @throws ValidationException
     */
    private function receivableFor(CreditDebitNote $note): Account
    {
        $customer = $note->customer;

        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer_id' => 'This note has no valid customer.',
            ]);
        }

        return $this->accounts->receivable($note->company, $customer->receivable_account_id);
    }

    /**
     * @throws ValidationException
     */
    private function payableFor(CreditDebitNote $note): Account
    {
        $supplier = $note->supplier;

        if ($supplier === null) {
            throw ValidationException::withMessages([
                'supplier_id' => 'This note has no valid supplier.',
            ]);
        }

        return $this->accounts->payable($note->company, $supplier->payable_account_id);
    }

    /**
     * The tax leg's account, if the note actually carries tax.
     *
     * @throws ValidationException
     */
    private function resolveTaxAccount(CreditDebitNote $note, string $taxTotal): ?Account
    {
        if (Money::of($taxTotal)->isZero()) {
            return null;
        }

        if ($note->tax_account_id === null) {
            throw ValidationException::withMessages([
                'tax_account_id' => 'This note carries tax, so a tax account is required to record it.',
            ]);
        }

        return $note->note_type->isSales()
            ? $this->accounts->tax($note->company, $note->tax_account_id)
            : $this->accounts->inputTax($note->company, $note->tax_account_id);
    }

    /**
     * Re-check every line-level adjustment against the source line's quantity.
     *
     * The same checks the draft path runs, for the same reason: a document-level
     * limit and a line-level limit can both be satisfied while the lines together
     * still mean something impossible, and only a re-check under the source lock
     * can be relied on - by the time this runs another note may have consumed the
     * quantity these lines claim.
     *
     * Lines with no source line are document-level and are covered entirely by the
     * grand-total check, so there is nothing to re-check for them.
     *
     * @param  Collection<int, CreditDebitNoteLine>  $lines
     *
     * @throws ValidationException
     */
    private function reassertLineQuantities(CreditDebitNote $note, Collection $lines): void
    {
        $type = $note->note_type;

        $field = $this->adjustments->sourceLineField($type);

        foreach ($lines as $line) {
            if ($line->{$field} === null) {
                continue;
            }

            $this->adjustments->assertLineWithinLimit(
                $note,
                $type,
                (int) $line->{$field},
                Money::of($line->quantity),
                "lines.{$line->line_number}.{$field}",
            );
        }
    }

    /**
     * Recompute a note's totals from its persisted lines.
     *
     * @param  Collection<int, CreditDebitNoteLine>  $lines
     * @return array{subtotal: string, discount_total: string, tax_total: string, grand_total: string}
     */
    private function recalculateTotals(Collection $lines): array
    {
        $subtotal = Money::zero();
        $discount = Money::zero();
        $tax = Money::zero();

        foreach ($lines as $line) {
            $gross = Money::of($line->quantity)->times(Money::of($line->unit_price));

            $subtotal = $subtotal->plus($gross);
            $discount = $discount->plus(Money::of($line->discount));
            $tax = $tax->plus(Money::of($line->tax_amount));
        }

        return [
            'subtotal' => $subtotal->toDatabase(),
            'discount_total' => $discount->toDatabase(),
            'tax_total' => $tax->toDatabase(),
            'grand_total' => $subtotal->minus($discount)->plus($tax)->toDatabase(),
        ];
    }

    private function counterDescription(CreditDebitNote $note): string
    {
        return $note->note_type->isSales() ? 'Accounts Receivable' : 'Accounts Payable';
    }

    /**
     * The note must still be pointed at the document that was locked.
     *
     * @throws ValidationException
     */
    private function assertSameSource(CreditDebitNote $note, SalesInvoice|PurchaseBill $source): void
    {
        $sourceId = $note->note_type->isSales() ? $note->sales_invoice_id : $note->purchase_bill_id;

        if ($sourceId !== $source->getKey()) {
            throw ValidationException::withMessages([
                'note' => 'This note is not pointed at the document it was locked against.',
            ]);
        }
    }
}
