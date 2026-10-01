<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_bills', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('supplier_id')
                ->constrained()
                ->restrictOnDelete();

            /*
             * Bill numbers are issued per company from the same
             * document_number_sequences table, type BILL. Never reused.
             */
            $table->string('bill_number', 50);

            $table->date('bill_date');
            $table->date('due_date');

            /*
             * Lifecycle mirrors invoices but its own set: DRAFT / POSTED /
             * PARTIALLY_PAID / PAID. Separate enum in the application to keep
             * the two concepts explicit, even where the state names overlap.
             */
            $table->string('status', 20)->default('DRAFT');

            /*
             * Totals stored only for the contractual document itself. The amount
             * the supplier is still owed is derived from allocations (and a bill
             * may be paid in parts). paid_total and balance_due are not columns.
             */
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_total', 20, 4)->default(0);
            $table->decimal('tax_total', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);

            $table->text('notes')->nullable();

            /*
             * Input tax account for this bill. REQUIRED whenever tax_total is
             * non-zero and validated as an ASSET in the same company by
             * TransactionAccountResolver.
             *
             * ASSET, not LIABILITY, and the distinction is the whole point of
             * having a separate column from the invoice's tax account: tax
             * recovered from a supplier is money the tax authority owes back, so
             * it is debited here. Tax charged on a sale is money this company
             * owes, so it is credited. Booking input tax as a liability reduction
             * would make the journal say the company owes its own suppliers less
             * than the goods are worth.
             */
            $table->foreignId('tax_account_id')
                ->nullable()
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->foreignId('journal_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'bill_number']);

            $table->index(['company_id', 'bill_date']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'supplier_id', 'status']);
            $table->index('journal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_bills');
    }
};
