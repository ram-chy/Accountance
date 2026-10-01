<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();

            /*
             | Company scope. Every account belongs to exactly one company, and
             | nothing in the application reads an account without filtering on
             | this column: an unscoped read is the one bug shape that leaks one
             | tenant's chart of accounts into another's response.
             */
            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | Adjacency list, self-referencing. The spec explicitly asks for
             | this and forbids a nested-set package: ledger reporting here
             | needs a parent lookup and a code prefix, not subtree reordering.
             |
             | nullOnDelete rather than cascade: removing a parent heading must
             | never silently destroy the child accounts that hold posted
             | history. Deletion of an account is separately guarded in
             | AccountService, so this FK is a database backstop rather than the
             | primary defence.
             */
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('accounts')
                ->nullOnDelete();

            /*
             | Account code. Unique per company, NOT globally unique: two
             | companies must be free to both call their current assets "1100".
             | The composite unique below makes that a database fact.
             */
            $table->string('code', 50);

            $table->string('name', 255);

            /*
             | One of ASSET, LIABILITY, EQUITY, REVENUE, EXPENSE. Stored as a
             | string so MySQL can use a CHECK constraint; the PHP enum casts it
             | so arbitrary strings are impossible from the application side.
             */
            $table->string('account_type', 20);

            /*
             | Explicit contra override, nullable = follow account_type.
             | See AccountingRules::normalBalanceFor(). CHECKed to the enum so
             | the column can never hold a side that is not DEBIT or CREDIT.
             */
            $table->string('normal_balance', 20)->nullable();

            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);

            /*
             | Marks an account that a future module owns (Cash, Accounts
             | Receivable, Tax Payable...). Phase 4 creates the capability and
             | creates NO such accounts - defaulting the ledger to a chart of
             | accounts would be inventing data the user never asked for.
             */
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'account_type']);
            $table->index(['company_id', 'is_active']);

        });

        /*
         | The type must agree with a known fundamental type, and the override
         | must be null or a real side. Enforced in the schema so a raw SQL
         | write or a future migration cannot introduce a sixth account type
         | behind the enum's back.
         */
        SchemaCheck::add(
            'accounts',
            "account_type in ('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE')",
            'accounts_account_type_check'
        );

        SchemaCheck::add(
            'accounts',
            "normal_balance is null or normal_balance in ('DEBIT','CREDIT')",
            'accounts_normal_balance_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
