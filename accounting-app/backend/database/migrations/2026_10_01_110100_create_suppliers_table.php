<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | Unique per company, not globally: two companies must both be free
             | to call their first supplier SUP-0001.
             */
            $table->string('supplier_code', 50);

            /*
             | Not unique, for the same reason customer names are not: two
             | suppliers may share a trading name, and the code is the identity.
             */
            $table->string('name', 255);

            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('tax_identifier', 100)->nullable();

            /*
             | The control account this supplier's payables are booked to. The
             | mirror of customers.receivable_account_id and governed by the
             | same rule: explicit data, validated (same company, LIABILITY type,
             | active), never a hard-coded id. restrictOnDelete so deleting the
             | account cannot quietly strip a supplier of its payable account.
             */
            $table->foreignId('payable_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'supplier_code']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
