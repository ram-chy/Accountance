<?php

namespace App\Services\Accounting\Notes;

use App\Enums\DocumentNumberType;
use App\Enums\NoteType;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\DocumentCalculator;
use App\Services\Accounting\DocumentNumberSequence;
use App\Services\Accounting\DocumentTaxContext;
use App\Services\Accounting\TransactionAccountResolver;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Credit and debit notes: the draft lifecycle.
 *
 * The counterpart of SalesInvoiceService and PurchaseBillService, split from
 * posting in exactly the same way - everything a draft can do is here, and the
 * one irreversible act lives in CreditDebitNotePostingService. A caller holding
 * only a reference to this class has no way to post a note, which is what makes
 * "never post from a controller" structural rather than a convention.
 *
 * WHAT IS REUSED, AND WHY IT IS REUSE RATHER THAN A NEW ENGINE
 *
 *  - DocumentCalculator calculates every line and every total. There is no
 *    `tax = amount * rate` anywhere in this module, and there is no second
 *    implementation of line arithmetic: a note is priced by the same code that
 *    prices an invoice, so the two document kinds cannot drift apart on a rounding
 *    decision.
 *  - DocumentTaxContext resolves configured taxes, with the side taken from the
 *    note's own type. A sales note therefore resolves OUTPUT taxes and a purchase
 *    note INPUT ones, and a note cannot attach an incompatible tax because
 *    TaxRuleResolver applies the side rule before any arithmetic happens.
 *  - TransactionAccountResolver decides whether a line's account is acceptable,
 *    with the role chosen from the note's type: revenue for a sales note,
 *    expense for a purchase note. One column, two enforced roles.
 *  - DocumentNumberSequence allocates the note number, and AccountingPeriodService
 *    guards the date. Neither is reimplemented here.
 *  - The posting path hands a described entry to JournalService and
 *    JournalPostingService, exactly as the invoice and bill posting services do.
 *
 * THE SOURCE DOCUMENT AND THE NOTE TYPE ARE FIXED AT CREATION
 *
 * UpdateCreditDebitNoteRequest has no rule for note_type, sales_invoice_id or
 * purchase_bill_id, so a draft note cannot be re-pointed at a different document.
 * That is deliberate. The source decides the counterparty, the tax side, which
 * account role each line may use, which lines are adjustable and the whole
 * adjustment-limit calculation; re-pointing a note would mean re-deriving all of
 * that from a document the existing lines have no relationship to. The cost is
 * that a draft raised against the wrong invoice has to be deleted and re-raised -
 * which costs a number that is never reused and nothing else. The alternative was
 * judged and rejected: it is the only way this module could be made to produce a
 * note whose lines reference one document and whose accounting references another.
 *
 * THE ADJUSTMENT LIMIT IS CHECKED HERE AS WELL AS AT POSTING
 *
 * Checking it on draft creation means the user is told "only 300 remains
 * adjustable" while they are still typing rather than after they have committed.
 * It is NOT the enforcement point - a draft has no accounting effect, and two
 * drafts can each be sized against the same remaining amount. Posting re-checks
 * it under a row lock on the source document, and that check is the one that
 * makes the limit true.
 */
class CreditDebitNoteService
{
    public function __construct(
        private readonly DocumentNumberSequence $numbers,
        private readonly AccountingPeriodService $periods,
        private readonly DocumentCalculator $calculator,
        private readonly TransactionAccountResolver $accounts,
        private readonly CreditDebitNoteAdjustmentService $adjustments,
    ) {}

    /**
     * Create a draft note.
     *
     * Wrapped in a transaction because the note, its lines, its allocated number
     * and the adjustment-limit check are four things that must agree, and the
     * number in particular must not be consumed by a note that then fails to
     * save.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createDraft(Company $company, User $actor, array $data): CreditDebitNote
    {
        $type = NoteType::from($data['note_type']);

        $source = $this->adjustments->resolveSource(
            $company,
            $type,
            (int) $data[$this->adjustments->sourceField($type)]
        );

        return DB::transaction(function () use ($company, $actor, $data, $type, $source) {
            /*
             * Lock the source document first, always before the note. Every
             * write path in this module takes these two locks in this order, which
             * is what keeps two notes against one invoice from deadlocking. See
             * CreditDebitNoteAdjustmentService::lockSource.
             */
            $source = $this->adjustments->lockSource($source);

            $note = new CreditDebitNote([
                'note_type' => $type->value,
                'note_date' => $data['note_date'],
                'reason' => $data['reason'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'tax_account_id' => $data['tax_account_id'] ?? null,
            ]);

            /*
             * forceFill for every server-owned column: company from the request
             * context, number from the sequence, status from the lifecycle, both
             * source references from the document the note was resolved against,
             * and the counterparty copied from THAT document rather than taken from
             * the request. The last one is why a note can never be raised against
             * someone else's invoice: there is no request field for it.
             */
            $note->forceFill([
                'company_id' => $company->getKey(),
                'note_number' => $this->numbers->nextFor($company, DocumentNumberType::CreditDebitNote),
                'status' => TransactionStatus::Draft->value,
                'sales_invoice_id' => $source instanceof SalesInvoice ? $source->getKey() : null,
                'purchase_bill_id' => $source instanceof PurchaseBill ? $source->getKey() : null,
                'customer_id' => $source instanceof SalesInvoice ? $source->customer_id : null,
                'supplier_id' => $source instanceof PurchaseBill ? $source->supplier_id : null,
                'created_by' => $actor->getKey(),
            ])->save();

            $totals = $this->writeLines($company, $note, $data['lines'] ?? []);

            $this->assertWithinLimit($note, $source, $totals['grand_total']);

            return $note->refresh();
        });
    }

    /**
     * Update a draft note.
     *
     * The status is re-read under a row lock rather than trusted from the routed
     * instance, for the reason SalesInvoiceService::updateDraft does the same: a
     * check made before the transaction leaves a window in which a concurrent
     * post commits between the check and this write, and the update would then
     * rewrite a document already in the accounting record.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function updateDraft(CreditDebitNote $note, Company $company, array $data): CreditDebitNote
    {
        return DB::transaction(function () use ($note, $company, $data) {
            $source = $this->adjustments->sourceFor($note);

            // Source before note, as everywhere else in this module.
            $source = $this->adjustments->lockSource($source);

            $fresh = CreditDebitNote::query()
                ->whereKey($note->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($fresh);

            /*
             * Phase 8: re-dating a draft may not target a closed period. A draft
             * has no journal yet, so this is the same check on the date alone that
             * JournalService::updateDraft makes - the posting path will make the
             * stricter one.
             */
            if (array_key_exists('note_date', $data)) {
                $this->periods->assertDateNotClosed($company, Carbon::parse($data['note_date']), 'note_date');
            }

            // Absent and explicit null are different requests; see the same
            // comment in CustomerReceiptService::updateDraft.
            foreach (['note_date', 'reason', 'reference', 'notes', 'tax_account_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fresh->{$field} = $data[$field];
                }
            }

            $fresh->save();

            $totals = array_key_exists('lines', $data)
                ? $this->writeLines($company, $fresh, $data['lines'])
                : [
                    'grand_total' => (string) $fresh->grand_total,
                ];

            $this->assertWithinLimit($fresh, $source, $totals['grand_total']);

            return $fresh->refresh();
        });
    }

    /**
     * Delete a draft note.
     *
     * Safe only because a draft has no journal: there is nothing in the accounting
     * record to lose, and the FK on credit_debit_note_lines cascades. The note
     * number is not returned to the sequence - an identifier issued to a document
     * that once existed must not be reissued.
     *
     * A posted note is refused here rather than at the route or the caller, so the
     * rule holds for any code path that reaches this service. That is the whole of
     * Section 12 of the brief: there is no cancellation mechanism here because
     * this application has none, and inventing one would be a second accounting
     * rule rather than an integration.
     *
     * @throws ValidationException
     */
    public function deleteDraft(CreditDebitNote $note): void
    {
        DB::transaction(function () use ($note) {
            $fresh = CreditDebitNote::query()
                ->whereKey($note->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($fresh);

            $fresh->delete();
        });
    }

    /**
     * Replace a note's lines and recompute its totals.
     *
     * Full replace rather than a diff, matching the invoice rule: a document whose
     * totals are derived from its lines has to be saved as a whole or not at all,
     * and a partial update cannot distinguish "remove line 3" from "line 3 was
     * never meant to be sent".
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{subtotal: string, discount_total: string, tax_total: string, grand_total: string}
     *
     * @throws ValidationException
     */
    private function writeLines(Company $company, CreditDebitNote $note, array $lines): array
    {
        $type = $note->note_type;

        /*
         * The note's own date decides the tax rate, not today's - a note raised in
         * March for a February tax position is priced at February's rate. And the
         * side comes from the note type, which is what stops a purchase note
         * resolving an OUTPUT tax.
         */
        $taxContext = new DocumentTaxContext(
            company: $company,
            date: $note->note_date->toDateString(),
            output: $type->isOutputTaxSide(),
        );

        /*
         * 'unit_price' for every note type, not 'unit_cost' for purchases. The
         * note table has one price column and one request field, so the parameter
         * is the same in all four cases - see the note-line migration for why a
         * single name was chosen over matching each source document's field.
         */
        $totals = $this->calculator->calculateDocument($lines, 'unit_price', $taxContext);

        $this->calculator->assertTaxAccountPresent(
            Money::of($totals['tax_total']),
            $note->tax_account_id
        );

        $accounts = $this->resolveLineAccounts($company, $type, $lines);

        $sourceLineField = $this->adjustments->sourceLineField($type);

        $note->forceFill([
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
        ])->save();

        $note->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            $calculated = $totals['lines'][$index];

            $sourceLineId = isset($line[$sourceLineField]) ? (int) $line[$sourceLineField] : null;

            /*
             * A source line reference is validated against the note's OWN source
             * document before it is written, not after. Two ids in two columns is
             * exactly the shape of bug that lets one document's quantity be
             * consumed by another document's adjustment, and the check is cheap
             * enough to belong here rather than only at posting time.
             */
            if ($sourceLineId !== null) {
                $this->adjustments->assertLineWithinLimit(
                    $note,
                    $type,
                    $sourceLineId,
                    Money::of($calculated['quantity']),
                    "lines.{$index}.{$sourceLineField}",
                );
            }

            $note->lines()->create([
                'line_number' => $calculated['line_number'],
                'description' => $line['description'] ?? null,

                // The source line reference goes in the column matching its kind,
                // because the two are separate FKs and a note line may reference
                // at most one of them.
                $sourceLineField => $sourceLineId,

                'quantity' => $calculated['quantity'],
                'unit_price' => $calculated['unit_price'],
                'discount' => $calculated['discount'],
                'tax_rate' => $calculated['tax_rate'],
                'tax_amount' => $calculated['tax_amount'],
                // See SalesInvoiceService::writeLines for why this is nullable, and
                // why a multi-tax line reports as unattributed rather than
                // attributing itself to one of several taxes.
                'tax_id' => $calculated['tax_id'],
                'line_total' => $calculated['line_total'],
                'account_id' => $accounts[$index]->getKey(),
            ]);
        }

        return $totals;
    }

    /**
     * Validate every line's account, collecting all failures.
     *
     * The role is chosen from the note type rather than supplied by the caller, so
     * a purchase note can never be validated against the revenue role or the
     * reverse - there is no call site at which that choice could be wrong.
     *
     * One bad account in a 40-line note should not cost 40 submissions to find, so
     * every line is validated and the errors gathered before anything is thrown,
     * each re-keyed to the line that caused it.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, Account>
     *
     * @throws ValidationException
     */
    private function resolveLineAccounts(Company $company, NoteType $type, array $lines): array
    {
        $resolve = $type->isSales() ? 'revenue' : 'expense';

        $resolved = [];
        $errors = [];

        foreach (array_values($lines) as $index => $line) {
            try {
                $resolved[$index] = $this->accounts->{$resolve}($company, (int) ($line['account_id'] ?? 0));
            } catch (ValidationException $e) {
                /*
                 * Re-keyed from the resolver's role name (revenue_account_id /
                 * expense_account_id) to account_id, which is the field the client
                 * actually sent. Without this a user submitting lines.account_id
                 * would be told about a field that appears nowhere in their request
                 * and in no response body.
                 */
                foreach ($e->errors() as $messages) {
                    $errors["lines.{$index}.account_id"] = $messages;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * @throws ValidationException
     */
    private function assertWithinLimit(
        CreditDebitNote $note,
        SalesInvoice|PurchaseBill $source,
        string $noteTotal,
    ): void {
        $net = $note->note_type->isSales()
            ? $this->adjustments->netAdjustmentForInvoice($source->getKey())
            : $this->adjustments->netAdjustmentForBill($source->getKey());

        $this->adjustments->assertWithinLimit(
            $note->note_type,
            $note->note_type->isSales() ? $source->invoice_number : $source->bill_number,
            Money::of($noteTotal),
            $net,
            Money::of($source->grand_total),
        );
    }

    /**
     * @throws ValidationException
     */
    private function assertDraft(CreditDebitNote $note): void
    {
        if (! $note->status->isDraft()) {
            throw ValidationException::withMessages([
                'note' => 'This note has been posted and is part of the accounting record. '
                    .'It cannot be edited or deleted; record a further note against the original document instead.',
            ]);
        }
    }
}
