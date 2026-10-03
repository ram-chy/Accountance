<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 - the credit/debit note header.
 *
 * TWO SOURCE COLUMNS, NOT A TYPE/ID PAIR
 *
 * The obvious shape for "every note references the document it adjusts" is a
 * polymorphic (source_document_type, source_document_id) pair - the same shape
 * journals.source_type / journals.source_id already uses. It is rejected here for
 * three reasons, in increasing order of how much they cost when wrong:
 *
 *  1. It cannot be enforced. With a real column pair, the database guarantees
 *     the referenced document exists. With a string type and an integer id, it
 *     guarantees nothing at all - the strongest check available is a CHECK that
 *     the type is one of two strings, which says nothing about whether the row
 *     exists, belongs to this company, or is an invoice rather than a bill.
 *  2. Two nullable foreign keys make every one of those checks declarative. The
 *     invoice FK says the invoice exists; the CHECK constraints below say the
 *     bill FK is null, that the customer is set exactly when the invoice is, and
 *     that the supplier is set exactly when the bill is. A note row cannot exist
 *     that names no document, or two documents, or a customer with no invoice.
 *  3. Deletion becomes safe by construction. Both FKs are RESTRICT, so an invoice
 *     that a posted note adjusts cannot be deleted out from under the note, and
 *     the restriction is the database's rather than a check some future code path
 *     has to remember to repeat.
 *
 * The cost is one nullable column and a couple of CHECK constraints instead of
 * two plain columns. source_document_type and source_document_id are still
 * exposed, derived, in CreditDebitNoteResource - a client gets a uniform shape
 * without the storage layer carrying an unvalidatable pair.
 *
 * THE CUSTOMER/SUPPLIER COLUMNS ARE DERIVED IN THE APPLICATION
 *
 * A note always belongs to the source document's counterparty. The service copies
 * customer_id or supplier_id from the invoice or bill it resolves, so a client
 * cannot point a note at its own customer while adjusting someone else's invoice.
 * They are stored rather than joined-through for the same reason sales_invoices
 * stores customer_id: they are on the hot path of every statement and report
 * query, and a four-table join to read them would be a second reason to keep the
 * CHECK constraints honest.
 *
 * `reason` is REQUIRED, and that is the one place this schema is stricter than
 * the brief's field list (which marks it as merely present). An adjustment with no
 * stated reason is an adjustment nobody can audit: the amount and the counterparty
 * are both self-evident from the note and the invoice it adjusts, so the reason is
 * the only part of the document that carries information not derivable from the
 * rest of the ledger. Every tax authority that regulates credit notes requires
 * one for the same reason. `reference` is optional, because an external reference
 * (a returns note, a supplier's own document number) does not exist for every
 * adjustment and inventing one would push users to type filler.
 *
 * NO STORED BALANCE COLUMNS
 *
 * There is no remaining_adjustable_amount, no adjusted_total, no
 * outstanding_after_note. Every one of those is a sum over posted notes, and a
 * stored copy of a sum is a second source of truth that can disagree with the
 * notes it summarises. CreditDebitNoteAdjustmentService computes them, and it
 * computes them under a lock on the source document when the answer has to be
 * financially exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_debit_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | Server-allocated from document_number_sequences, type
             | CREDIT_DEBIT_NOTE, prefixed CDN-. Never client-supplied. One
             | sequence serves all four note types - see DocumentNumberType for
             | why, which is the same reasoning that gives deposits, withdrawals
             | and transfers one CBN- counter.
             */
            $table->string('note_number', 50);

            /*
             | SALES_CREDIT_NOTE / SALES_DEBIT_NOTE / PURCHASE_CREDIT_NOTE /
             | PURCHASE_DEBIT_NOTE. Free text rather than an enum column because
             | that is the convention every other document table in this schema
             | follows; App\Enums\NoteType is the application's contract and this
             | column only stores what it says.
             */
            $table->string('note_type', 30);

            /*
             | The document being adjusted. Exactly one of these is set, and the
             | CHECK constraints below say so.
             */
            $table->foreignId('sales_invoice_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('purchase_bill_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            /*
             | Copied from the source document's counterparty by the service, and
             | constrained to be present exactly when the matching source is. The
             | database therefore cannot hold a sales note with no customer or a
             | sales note naming a customer the invoice does not belong to - the
             | latter is refused in the application, because "the invoice's
             | customer" is a rule about two rows and not a column constraint.
             */
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            /*
             | The business date of the adjustment, not created_at. A credit note
             | raised in March for a January invoice belongs in March - which is
             | the period it posts into, and the one the tax report groups it by.
             | Backdating is not restricted here; AccountingPeriodService decides
             | at posting time, as it does for every other document.
             */
            $table->date('note_date');

            /*
             | DRAFT / POSTED only. TransactionStatus is reused - it is the
             | application's document lifecycle enum - and a note simply never
             | enters PARTIALLY_PAID or PAID, because a note is not something a
             | customer pays; the money side of that is the invoice or bill it
             | adjusts. The CHECK constraint makes the narrower set a fact rather
             | than a convention, so no code path can leave a note marked PAID.
             */
            $table->string('status', 20)->default('DRAFT');

            /*
             | The note's own contractual figures, computed server-side from its
             | lines on every write, exactly as sales_invoices and purchase_bills
             | are. The note stores its own calculation rather than copying the
             | source document's because it is a different amount: a partial
             | credit note of 20 on an invoice of 110 has a grand_total of 20, and
             | a debit note has one even though the invoice it adjusts does not
             | increase by that much in any sense the invoice knows about.
             */
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_total', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);

            /*
             | Why the adjustment was made. See the class docblock: required,
             | because it is the only part of the note not derivable from the
             | ledger.
             */
            $table->text('reason');

            /*
             | An external reference - the customer's returns note number, the
             | supplier's own document number. Optional; not every adjustment has
             | one.
             */
            $table->string('reference', 100)->nullable();

            $table->text('notes')->nullable();

            /*
             | Where the note's tax goes. A single column rather than one for
             | output and one for input, because the note's side is already
             | decided by note_type: a sales note charges OUTPUT tax and needs a
             | LIABILITY account, a purchase note recovers INPUT tax and needs an
             | ASSET. TransactionAccountResolver::tax() and ::inputTax() enforce
             | the right one per side, so one column cannot be misused - it can
             | only be absent, which the calculator refuses when the computed tax
             | is non-zero.
             */
            $table->foreignId('tax_account_id')
                ->nullable()
                ->constrained('accounts')
                ->restrictOnDelete();

            /*
             | The accounting journal this note produced. Set in the same
             | transaction that posts it. nullOnDelete for the same reason as
             * sales_invoices.journal_id: a journal is not deletable once posted,
             | but the FK should not pretend otherwise, and a draft journal removed
             | directly by an administrator should orphan the document rather than
             | delete the document with it.
             */
            $table->foreignId('journal_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            /*
             | The audit record of the post, taken from the authenticated user in
             | CreditDebitNotePostingService and never from a payload - the reason
             * Phase 4 established and the reason it still holds.
             */
            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'note_number']);

            /*
             | The two real query shapes: a listing filtered by company, type and
             | date; and - the one that actually matters for performance and for
             | correctness under concurrency - the adjustment service summing
             | posted notes for one source document. That sum runs on every create,
             | update and post, so it gets its own index rather than relying on
             | the listing one.
             */
            $table->index(['company_id', 'note_date']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'note_type']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'supplier_id']);
            $table->index(['sales_invoice_id', 'status']);
            $table->index(['purchase_bill_id', 'status']);
            $table->index('journal_id');
        });

        /*
         | Exactly one source document. Written as an inequality of the two
         | IS NULL predicates rather than as `xor(...)` because `xor` is a MySQL
         | function name and using a bare word where a function was expected would
         | parse ambiguously; the predicate form says the same thing and reads as
         | the rule it is.
         */
        SchemaCheck::add(
            'credit_debit_notes',
            '(sales_invoice_id is null) <> (purchase_bill_id is null)',
            'credit_debit_notes_single_source_check'
        );

        /*
         | The counterparty is present exactly when its kind of source is. A sales
         | note always has a customer and never a supplier; a purchase note always
         | has a supplier and never a customer.
         */
        SchemaCheck::add(
            'credit_debit_notes',
            '((customer_id is null) = (sales_invoice_id is null)) and ((supplier_id is null) = (purchase_bill_id is null))',
            'credit_debit_notes_counterparty_check'
        );

        /*
         | The narrower status set. sales_invoices uses all four values of
         | TransactionStatus and carries no such constraint; a note never does,
         | so the constraint is here to make that structural rather than
         | conventional.
         */
        SchemaCheck::add(
            'credit_debit_notes',
            "status in ('DRAFT','POSTED')",
            'credit_debit_notes_status_check'
        );

        /*
         | The posting invariant, as a database fact: a note that is POSTED has a
         | poster and a posting time. The journal half of that is deliberately NOT
         | in this constraint, and the reason is a MySQL restriction rather than a
         | design choice - a column carrying a referential action of SET NULL
         | cannot appear in a CHECK, because dropping the referencing row would
         | change the very value the constraint tests. journal_id is exactly that
         | column. This was found by running the migration, not by reading about it.
         |
         | So the schema proves two of the four halves, and
         | CreditDebitNotePostingService writes all four in a single statement
         | inside the transaction that posts the journal - the same guarantee
         | sales_invoices and purchase_bills rely on, and the reason neither of
         | them claims a check it does not have.
         */
        SchemaCheck::add(
            'credit_debit_notes',
            "(status <> 'POSTED') or (posted_by is not null and posted_at is not null)",
            'credit_debit_notes_posted_fields_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_debit_notes');
    }
};
