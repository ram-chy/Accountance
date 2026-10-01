<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         | One counter per document type per company.
         |
         | Phase 4 established the rule with journal_number_sequences: numbers
         | are never reused, so the counter is a row that only moves forward
         | rather than MAX(number) + 1. Two derivation attempts were rejected.
         |
         |  1. MAX(journal_number) + 1 reuses a number after a delete, which
         |     leaves two distinct documents sharing one identifier in the audit
         |     trail.
         |  2. Four separate tables (invoice_/bill_/receipt_/payment_sequences)
         |     would work, but they would be four identical schemas whose only
         |     difference is a constant. The type is a column here instead.
         |
         | A UNIQUE (company_id, document_type) is what makes "exactly one
         | counter per type per company" a database fact, and it is also what the
         | insertOrIgnore race in DocumentNumberSequence relies on.
         */
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | INVOICE, BILL, RECEIPT, PAYMENT. Free text rather than an enum
             | column because the set is code-defined in
             | App\Enums\DocumentNumberType; the enum is the application's
             | contract and this column just stores it.
             */
            $table->string('document_type', 30);

            $table->unsignedBigInteger('last_number')->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'document_type']);
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | Cross-company references are refused twice over: the FK guarantees
             | the row exists, and TransactionAccountResolver / the posting
             | services guarantee it belongs to this company and is usable. The
             | FK alone cannot express "same company", because customers.id is
             | globally unique and says nothing about which tenant owns it.
             */
            $table->foreignId('customer_id')
                ->constrained()
                ->restrictOnDelete();

            /*
             | Server-allocated from document_number_sequences, formatted with the
             | company's invoice_number_prefix. Never client-supplied and never a
             | timestamp or UUID: this is the identifier a customer will quote
             | back, so it has to be short, ordered and stable.
             */
            $table->string('invoice_number', 50);

            /*
             | The business date, distinct from created_at. An invoice entered
             | late still belongs to the month it was issued, and the accounting
             | period check runs against this column.
             */
            $table->date('invoice_date');

            $table->date('due_date');

            /*
             | DRAFT / POSTED / PARTIALLY_PAID / PAID. Deliberately not the same
             | enum as JournalStatus: a journal records whether an entry is in
             | the ledger, an invoice also records whether the customer has
             | settled it. Collapsing them would put "PAID" on a journal, which
             * means nothing to an accountant.
             */
            $table->string('status', 20)->default('DRAFT');

            /*
             | Document totals, computed server-side from the lines on every
             | write. These are stored because they are the invoice's own
             | contractual figures - the number the customer owes - and are
             | recomputed inside the same transaction that writes the lines, so
             | they cannot drift from them.
             |
             | paid_total and balance_due are NOT here. They are a pure function
             | of the allocation rows (see CustomerReceiptAllocation), and storing
             | a second copy would create a figure that can disagree with the
             | payments it claims to summarise - exactly the duplicated ledger
             | the Phase 4 report refused to build. Derived in the API layer.
             */
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_total', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);

            $table->text('notes')->nullable();

            /*
             | Where tax is charged. Null when no line carries a rate.
             |
             | An explicit column rather than a lookup, because the brief forbids
             | hard-coded account ids and a company may run output tax through
             | several accounts by jurisdiction. It is validated (LIABILITY, same
             | company, active) and REQUIRED whenever tax_total is non-zero - a
             | taxable invoice with nowhere to book the tax would produce an
             | unbalanced journal or silently drop the liability.
             */
            $table->foreignId('tax_account_id')
                ->nullable()
                ->constrained('accounts')
                ->restrictOnDelete();

            /*
             | The accounting journal this invoice produced. Null while the
             | invoice is a draft, set in the same transaction that posts the
             | journal. ON DELETE SET NULL: a journal is not deletable once
             | posted, but the FK should not pretend otherwise - and if an
             | administrator ever removes a draft journal directly, the invoice
             | becomes an orphan for that document rather than vanishing with it.
             */
            $table->foreignId('journal_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            /*
             | The audit record of the post operation, exactly as Phase 4 does it
             | for journals: from the authenticated user, never the payload.
             */
            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'invoice_number']);

            $table->index(['company_id', 'invoice_date']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id', 'status']);
            $table->index('journal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('document_number_sequences');
    }
};
