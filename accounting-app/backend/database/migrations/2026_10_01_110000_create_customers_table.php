<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            /*
             | Company scope. Every read filters on this column; an unscoped read
             | is the one shape that leaks one tenant's customer list into
             | another's response. Never accepted from a client - it comes from
             | CompanyContext and is assigned with forceFill() by CustomerService.
             */
            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | Human-facing code, unique per company but NOT globally unique.
             | Two companies must be free to both call their customer C-0001;
             | one company must not have two. The composite unique below makes
             | that a database fact rather than an application convention.
             */
            $table->string('customer_code', 50);

            /*
             | Deliberately not unique. The brief allows duplicate customer names
             | where the business requires it, and two different people may
             | legitimately share a name. The code is the identifier; the name is
             | a label. A unique index here would be an invented business rule.
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
             | The control account this customer's receivables are booked to.
             |
             | Stored on the customer rather than looked up by name or code
             | because it is a property of the relationship: one customer may
             | settle through a clearing account while the rest post straight to
             | Accounts Receivable. The brief forbids hard-coded account ids, so
             | the choice is made explicit data and validated on every write -
             | same company, ASSET type, active - by TransactionAccountResolver.
             |
             | restrictOnDelete: a customer pointing at an account must stop that
             | account being deleted, or the customer's receivable would silently
             | lose the account it books to. nullOnDelete would be worse - the
             | customer would keep existing with a null it cannot be invoiced
             | against and no obvious reason why.
             */
            $table->foreignId('receivable_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            /*
             | is_active is absent from the model's fillable and only ever moved
             | by CustomerService, matching how Account::is_active is handled in
             | Phase 4: deactivation has consequences and is not a plain
             | attribute edit.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'customer_code']);

            /*
             | Every customer list is company-scoped and filtered by activity,
             | so this pair is the hot path.
             */
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
