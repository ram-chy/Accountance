<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('supplier_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('payment_number', 50);

            $table->date('payment_date');

            $table->decimal('amount', 20, 4)->default(0);

            /*
             * The bank/cash account the money went out of. Must be an ASSET
             * account in the same company, active.
             */
            $table->foreignId('payment_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->string('reference', 255)->nullable();
            $table->text('notes')->nullable();

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

            $table->unique(['company_id', 'payment_number']);

            $table->index(['company_id', 'payment_date']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'supplier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
