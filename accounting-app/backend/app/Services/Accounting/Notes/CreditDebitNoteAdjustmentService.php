<?php

namespace App\Services\Accounting\Notes;

use App\Enums\NoteType;
use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\CreditDebitNoteLine;
use App\Models\PurchaseBill;
use App\Models\PurchaseBillLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Everything Phase 11 has to know about how much of a document is left to adjust.
 *
 * This is the only place the adjustment arithmetic exists. It is deliberately a
 * service of its own rather than a private method on the draft service or the
 * posting service, because three callers need it and they are on different paths:
 * creating a draft (so the user is told early), updating one (because the amount
 * may have grown), and posting one (because posting is the moment the adjustment
 * becomes a fact, and it is the only one that matters). One implementation means
 * the number a user is shown when composing a note, and the number the system
 * enforces when committing it, cannot be two different answers.
 *
 * NO STORED REMAINING BALANCE
 *
 * Everything here is a sum over posted notes, computed on demand. A
 * `remaining_adjustable_amount` column was considered and rejected for the reason
 * Phase 4 rejected `ledger_balances` and Phase 5 rejected `paid_total`: it is a
 * second copy of a figure that is already derivable, and the two can disagree
 * with nothing to notice. Every number this class returns is a query.
 *
 * THE INVARIANT
 *
 * For a source document worth G:
 *
 *     net   = SUM(posted credit notes) - SUM(posted debit notes)
 *     remaining = G - net
 *
 * and a new note of amount A is accepted only when A <= remaining. Credits and
 * debits share one rule rather than having one each, and that is the point: a
 * credit note for 700 against a 1,000 invoice leaves 300, and a debit note against
 * that same invoice may then be at most 300 - it may undo part of the credit, but
 * it may not inflate the receivable beyond what the invoice was originally for.
 * Without that, an invoice worth 1,000 could be debited without limit and become
 * an arbitrarily large receivable supported by no document at all. Two separate
 * per-direction rules would each be simpler to state and neither would prevent it.
 *
 * `remaining` is clamped at zero for reporting, but the clamp is not the
 * enforcement mechanism - a negative remaining would mean the invariant had
 * already been broken by some other path, and hiding that behind a clamp would
 * make the bug invisible. It is enforced by every write this class guards.
 *
 * WHY ONLY POSTED NOTES COUNT
 *
 * A draft note has no journal, so it has not adjusted anything. Two drafts can
 * each be sized against the same remaining amount and only one of them will post;
 * that is not a defect, it is the same two-step prepare/commit shape every
 * document in this application has. It does mean the limit is re-checked at post
 * time, and re-checked under a lock - see CreditDebitNotePostingService.
 *
 * AND WHY ADJUSTMENT LIMITS IGNORE PAYMENTS
 *
 * The limit is against what the document was for, not against what remains
 * unpaid. A credit note reduces what was invoiced; it does not receive money, and
 * treating an unpaid balance as the limit would make it impossible to credit an
 * invoice that had already been settled - which is precisely the case Section 29
 * of the brief says must still work. Payment allocations are not touched here at
 * all, and no receipt or payment is rewritten by a note.
 */
class CreditDebitNoteAdjustmentService
{
    /**
     * Resolve the document a note of this type adjusts, and prove it is usable.
     *
     * Company-scoped by construction, so an id belonging to another tenant is
     * indistinguishable from one that does not exist - the message says the
     * document "does not belong to the active company", which is true of both and
     * discloses neither.
     *
     * @throws ValidationException
     */
    public function resolveSource(Company $company, NoteType $type, int $sourceId): SalesInvoice|PurchaseBill
    {
        if ($type->isSales()) {
            $invoice = SalesInvoice::query()
                ->where('company_id', $company->getKey())
                ->whereKey($sourceId)
                ->first();

            if ($invoice === null) {
                throw ValidationException::withMessages([
                    'sales_invoice_id' => 'The selected sales invoice does not belong to the active company.',
                ]);
            }

            $this->assertEligible($invoice->status, $invoice->invoice_number, 'sales_invoice_id');

            return $invoice;
        }

        $bill = PurchaseBill::query()
            ->where('company_id', $company->getKey())
            ->whereKey($sourceId)
            ->first();

        if ($bill === null) {
            throw ValidationException::withMessages([
                'purchase_bill_id' => 'The selected purchase bill does not belong to the active company.',
            ]);
        }

        $this->assertEligible($bill->status, $bill->bill_number, 'purchase_bill_id');

        return $bill;
    }

    /**
     * The document this note already adjusts.
     *
     * Not company-scoped: the caller here is always operating on a note that was
     * itself resolved to the active company, and its source is reachable through
     * the foreign key. Company scoping is applied where the *client* supplies the
     * id, which is resolveSource() above.
     *
     * @throws ValidationException
     */
    public function sourceFor(CreditDebitNote $note): SalesInvoice|PurchaseBill
    {
        $source = $note->sourceDocument()->first();

        if ($source === null) {
            /*
             * Only reachable if the referenced document was removed out from under
             * the note, which both RESTRICT foreign keys refuse. Treated as a
             * validation error naming the field rather than a 500: the user's
             * request is what surfaced it.
             */
            throw ValidationException::withMessages([
                $this->sourceField($note->note_type) => 'The document this note adjusts no longer exists.',
            ]);
        }

        return $source;
    }

    /**
     * Credits minus debits already posted against a document.
     */
    public function netAdjustmentForInvoice(int $invoiceId): Money
    {
        return $this->netAdjustment($this->postedNotesQuery()
            ->where('sales_invoice_id', $invoiceId));
    }

    /**
     * Credits minus debits already posted against a bill.
     */
    public function netAdjustmentForBill(int $billId): Money
    {
        return $this->netAdjustment($this->postedNotesQuery()
            ->where('purchase_bill_id', $billId));
    }

    /**
     * How much of an invoice may still be adjusted.
     */
    public function remainingForInvoice(SalesInvoice $invoice): Money
    {
        return $this->remaining(Money::of($invoice->grand_total), $this->netAdjustmentForInvoice($invoice->getKey()));
    }

    /**
     * How much of a bill may still be adjusted.
     */
    public function remainingForBill(PurchaseBill $bill): Money
    {
        return $this->remaining(Money::of($bill->grand_total), $this->netAdjustmentForBill($bill->getKey()));
    }

    /**
     * Refuse a note that would adjust more than the source document has left.
     *
     * $noteTotal is the note's own SERVER-CALCULATED grand total, never a value
     * from the request. There is no version of this method that accepts a
     * client-supplied amount, because a client that could name its own limit
     * could also name a larger one.
     *
     * @param  Money  $netAdjustment  credits minus debits already posted, so the caller can pass the figure it already has rather than making this issue a second query
     *
     * @throws ValidationException
     */
    public function assertWithinLimit(
        NoteType $type,
        string $sourceNumber,
        Money $noteTotal,
        Money $netAdjustment,
        Money $sourceTotal,
    ): void {
        $remaining = $this->remaining($sourceTotal, $netAdjustment);

        if ($noteTotal->greaterThan($remaining)) {
            throw ValidationException::withMessages([
                'grand_total' => sprintf(
                    'This %s is for %s, but only %s of %s remains adjustable. '
                    .'A note cannot adjust more than the document it adjusts is worth.',
                    strtolower($type->label()),
                    $noteTotal,
                    $remaining,
                    $sourceNumber
                ),
            ]);
        }
    }

    /**
     * Net adjusted quantity on one invoice line: credits minus debits.
     *
     * No exclusion parameter, and the absence is deliberate. Every caller
     * validates a note that is still DRAFT, so the note being checked is not in
     * the posted set this sums over and cannot be counted against itself. Adding
     * an exclusion for a case that cannot arise would be a second, untested path
     * through the arithmetic that could disagree with this one.
     */
    public function netQuantityForInvoiceLine(int $invoiceLineId): Money
    {
        return $this->netLineQuantity(
            $this->postedLineQuery()
                ->where('credit_debit_note_lines.sales_invoice_line_id', $invoiceLineId)
        );
    }

    /**
     * Net adjusted quantity on one bill line: credits minus debits.
     */
    public function netQuantityForBillLine(int $billLineId): Money
    {
        return $this->netLineQuantity(
            $this->postedLineQuery()
                ->where('credit_debit_note_lines.purchase_bill_line_id', $billLineId)
        );
    }

    /**
     * Validate one note line's source-line reference and its quantity.
     *
     * Four checks, all server-side:
     *
     *  1. The referenced line exists.
     *  2. It belongs to the note's OWN source document - this is the one that
     *     matters most, because the two ids are separate columns and nothing else
     *     would catch a note line pointing at an invoice that is not the invoice
     *     this note adjusts. Without it a credit note against invoice 1 could
     *     consume the quantity of invoice 2's line, and invoice 2 could then be
     *     credited twice over.
     *  3. The kind matches the note's type - a purchase note may not reference a
     *     sales invoice line.
     *  4. The adjusted quantity stays within [0, the source line's quantity],
     *     after this note's own contribution.
     *
     * @param  string  $sourceLineField  the request field the client sent the id in, so an error is actionable
     *
     * @throws ValidationException
     */
    public function assertLineWithinLimit(
        CreditDebitNote $note,
        NoteType $type,
        int $sourceLineId,
        Money $quantity,
        string $sourceLineField,
    ): void {
        if ($type->isSales()) {
            $line = SalesInvoiceLine::query()->whereKey($sourceLineId)->first();

            if ($line === null || $line->invoice?->getKey() !== $note->sales_invoice_id) {
                throw ValidationException::withMessages([
                    $sourceLineField => 'The selected invoice line does not belong to the invoice this note adjusts.',
                ]);
            }

            $net = $this->netQuantityForInvoiceLine($sourceLineId);

            $this->assertQuantityWithin($note, $net, $quantity, Money::of($line->quantity), $sourceLineField);

            return;
        }

        $line = PurchaseBillLine::query()->whereKey($sourceLineId)->first();

        if ($line === null || $line->bill?->getKey() !== $note->purchase_bill_id) {
            throw ValidationException::withMessages([
                $sourceLineField => 'The selected bill line does not belong to the bill this note adjusts.',
            ]);
        }

        $net = $this->netQuantityForBillLine($sourceLineId);

        $this->assertQuantityWithin($note, $net, $quantity, Money::of($line->quantity), $sourceLineField);
    }

    /**
     * Per-line adjustable figures for an invoice, for the frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function adjustableLinesForInvoice(SalesInvoice $invoice): array
    {
        return $this->adjustableLines(
            sourceLines: $invoice->lines()->get(),
            netQuantityFor: fn (int $lineId) => $this->netQuantityForInvoiceLine($lineId),
            descriptionField: 'description',
            priceField: 'unit_price',
            taxRateField: 'tax_rate',
        );
    }

    /**
     * Per-line adjustable figures for a bill.
     *
     * @return array<int, array<string, mixed>>
     */
    public function adjustableLinesForBill(PurchaseBill $bill): array
    {
        return $this->adjustableLines(
            sourceLines: $bill->lines()->get(),
            netQuantityFor: fn (int $lineId) => $this->netQuantityForBillLine($lineId),
            descriptionField: 'description',
            priceField: 'unit_cost',
            taxRateField: 'tax_rate',
        );
    }

    /**
     * Lock a source document's row for the duration of the current transaction.
     *
     * This is what makes the limit atomic rather than merely checked. Two
     * concurrent posts of notes against the same invoice both want to read "300
     * remaining"; the first to acquire this lock serialises the second until the
     * first has committed its journal and flipped its status, at which point the
     * second reads the committed sum and is refused if it no longer fits.
     *
     * Without it, both transactions read the same pre-commit state, both decide
     * they fit, and the invoice ends up credited by more than it was worth - a
     * silent over-credit with no journal imbalance anywhere to reveal it.
     *
     * The same row must be locked in the same relative order everywhere, or two
     * transactions touching two notes on one invoice could deadlock. Every caller
     * locks the SOURCE first and the NOTE second; see CreditDebitNotePostingService
     * for the ordering that follows from this.
     *
     * @return SalesInvoice|PurchaseBill the locked row, re-read from the database
     */
    public function lockSource(SalesInvoice|PurchaseBill $source): SalesInvoice|PurchaseBill
    {
        return $source::query()
            ->whereKey($source->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * The request field a source document id arrives in for a note type.
     */
    public function sourceField(NoteType $type): string
    {
        return $type->isSales() ? 'sales_invoice_id' : 'purchase_bill_id';
    }

    /**
     * The request field a source line id arrives in for a note type.
     */
    public function sourceLineField(NoteType $type): string
    {
        return $type->isSales() ? 'sales_invoice_line_id' : 'purchase_bill_line_id';
    }

    /**
     * A draft note is refused a source document that has never reached the ledger.
     *
     * The reason is not a rule preference. A draft invoice has no journal, so it
     * has no value in the accounts - there is nothing for a credit note to adjust,
     * and posting one would create a receivable movement against a document the
     * ledger has never heard of. The correction to a mistake in a draft invoice is
     * to fix the draft, which is what it is for.
     *
     * @throws ValidationException
     */
    private function assertEligible(TransactionStatus $status, string $sourceNumber, string $field): void
    {
        if ($status->isDraft()) {
            throw ValidationException::withMessages([
                $field => sprintf(
                    'Document [%s] is still a draft and has not been posted. '
                    .'Only a posted document can be adjusted - edit the draft itself while it is wrong.',
                    $sourceNumber
                ),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertQuantityWithin(
        CreditDebitNote $note,
        Money $net,
        Money $quantity,
        Money $sourceQuantity,
        string $field,
    ): void {
        /*
         * `net` is the adjustment made by OTHER posted notes; this note is still a
         * draft and so contributes nothing to it. `after` is therefore always the
         * quantity this line would have been adjusted by once this note posts -
         * which is the number the limit has to be stated against.
         */
        $after = $note->isCredit()
            ? $net->plus($quantity)
            : $net->minus($quantity);

        if ($after->isNegative()) {
            throw ValidationException::withMessages([
                $field => 'This adjustment would credit back more than has been debited on that line. '
                    .'A line cannot be debited below the quantity it was invoiced with.',
            ]);
        }

        if ($after->greaterThan($sourceQuantity)) {
            throw ValidationException::withMessages([
                $field => sprintf(
                    'Only %s of this line remains adjustable, and this note would adjust %s of it.',
                    $sourceQuantity->minus($net),
                    $quantity
                ),
            ]);
        }
    }

    /**
     * @param  Collection<int, SalesInvoiceLine|PurchaseBillLine>  $sourceLines
     * @param  \Closure(int): Money  $netQuantityFor
     * @return array<int, array<string, mixed>>
     */
    private function adjustableLines(Collection $sourceLines, \Closure $netQuantityFor, string $descriptionField, string $priceField, string $taxRateField): array
    {
        $idField = $sourceLines->first() instanceof SalesInvoiceLine ? 'sales_invoice_line_id' : 'purchase_bill_line_id';

        $rows = [];

        foreach ($sourceLines as $line) {
            $net = $netQuantityFor($line->getKey());

            /*
             * A line-level note moves the balance in one direction only: a credit
             * note consumes the invoiced quantity, a debit note may only give back
             * quantity that some other note has already consumed. So the figure
             * reported is whichever of the two is not negative, and zero when both
             * are - which is the state where nothing further can be credited and
             * nothing can be debited.
             */
            $remaining = $net->isNegative() ? Money::zero() : Money::of($line->quantity)->minus($net);

            $rows[] = [
                $idField => $line->getKey(),
                'line_number' => $line->line_number,
                'description' => $line->{$descriptionField},
                'quantity' => Money::of($line->quantity)->toDatabase(),
                $priceField => Money::of($line->{$priceField})->toDatabase(),
                $taxRateField => Money::of($line->tax_rate)->toDatabase(),
                'tax_id' => $line->tax_id,
                'line_total' => Money::of($line->line_total)->toDatabase(),
                'adjusted_quantity' => $net->toDatabase(),
                'remaining_quantity' => $remaining->toDatabase(),
                'is_adjustable' => $remaining->isPositive(),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<CreditDebitNote>  $query
     */
    private function netAdjustment(Builder $query): Money
    {
        /*
         * Raw SQL for the CASE, because the sign depends on a data column and not
         * on a constant that could be folded into a where clause. The credit-note
         * list is passed in rather than interpolated so the two criteria cannot
         * drift apart, and DECIMAL sums are exact in MySQL so the string coming
         * back needs no re-rounding.
         */
        $credits = implode(', ', array_map(
            fn (string $case) => "'".$case."'",
            [NoteType::SalesCreditNote->value, NoteType::PurchaseCreditNote->value]
        ));

        return Money::of((string) $query
            ->selectRaw("COALESCE(SUM(CASE WHEN note_type IN ({$credits}) THEN grand_total ELSE -grand_total END), 0) as net")
            ->value('net'));
    }

    /**
     * @return Builder<CreditDebitNote>
     */
    private function postedNotesQuery(): Builder
    {
        return CreditDebitNote::query()
            ->where('status', TransactionStatus::Posted->value);
    }

    /**
     * Posted note lines, joined to their note for the status and type.
     *
     * Deliberately no `select('credit_debit_note_lines.*')` here, unlike most joins
     * in this codebase. Every caller wants one aggregate over the joined rows, and
     * selecting the line columns alongside a SUM() puts a non-aggregated column in
     * an aggregated query - which MySQL refuses outright under only_full_group_by,
     * the default. Leaving the select unset means the caller's selectRaw is the only
     * thing in the column list, which is what the query actually wants.
     *
     * @return Builder<CreditDebitNoteLine>
     */
    private function postedLineQuery(): Builder
    {
        return CreditDebitNoteLine::query()
            ->join('credit_debit_notes', 'credit_debit_notes.id', '=', 'credit_debit_note_lines.credit_debit_note_id')
            ->where('credit_debit_notes.status', TransactionStatus::Posted->value);
    }

    /**
     * @param  Builder<CreditDebitNoteLine>  $query
     */
    private function netLineQuantity(Builder $query): Money
    {
        $credits = implode(', ', array_map(
            fn (NoteType $case) => "'".$case->value."'",
            [NoteType::SalesCreditNote, NoteType::PurchaseCreditNote]
        ));

        $net = Money::of((string) $query
            ->selectRaw("COALESCE(SUM(CASE WHEN credit_debit_notes.note_type IN ({$credits}) THEN credit_debit_note_lines.quantity ELSE -credit_debit_note_lines.quantity END), 0) as net")
            ->value('net'));

        return $net;
    }

    /**
     * G minus the net already adjusted, floored at zero.
     */
    private function remaining(Money $sourceTotal, Money $netAdjustment): Money
    {
        $remaining = $sourceTotal->minus($netAdjustment);

        return $remaining->isNegative() ? Money::zero() : $remaining;
    }
}
