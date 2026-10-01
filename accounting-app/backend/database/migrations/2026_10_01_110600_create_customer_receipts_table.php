<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_receipts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('customer_id')
                ->constrained()
                ->restrictOnDelete();

            /*
             * Receipt number, issued from document_number_sequences with type
             * RECEIPT. Unique per company.
             */
            $table->string('receipt_number', 50);

            /*
             * The date money was received. Becomes the accounting date for the
             * resulting journal.
             */
            $table->date('receipt_date');

            /*
             * The total amount received. Allocations must not exceed this, and
             * this amount is what drives the journal lines (Dr Bank, Cr AR) for
             * the allocation set. Stored once, computed server-side from
             * allocations during posting to guarantee consistency.
             */
            $table->decimal('amount', 20, 4)->default(0);

            /*
             * The bank/cash account the money went into. Must be an ASSET
             * account (usually Cash or Bank) in the same company, active.
             */
            $table->foreignId('payment_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->string('reference', 255)->nullable();
            $table->text('notes')->nullable();

            /*
             * Lifecycle: DRAFT / POSTED. Payments are not paid/partially-paid
             * on the receipt itself - that status lives on the invoices the
             * receipt is allocated against. Keeping receipt status minimal
             * matches the journal lifecycle.
             */
            $table->string('status', 20)->default('DRAFT');

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

            $table->unique(['company_id', 'receipt_number']);

            $table->index(['company_id', 'receipt_date']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_receipts');
    }
};
