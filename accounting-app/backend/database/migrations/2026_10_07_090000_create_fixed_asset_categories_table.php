<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 - fixed asset categories.
 *
 * A category is master data, not a document: it holds no amounts, no dates that
 * belong to any particular asset, and no balances of any kind. Its entire content
 * is "assets of this kind are written down this way, over this long, and their
 * money goes here".
 *
 * THE FIVE ACCOUNT COLUMNS ARE A TEMPLATE, NOT A MAPPING SERVICE
 *
 * The brief calls this "asset accounts / account mapping", and it is tempting to
 * build a separate mapping table the way Phase 10 did for taxes. That would be
 * wrong here for one reason: a tax's mapping is a live relationship consulted on
 * every calculation, whereas a category's accounts are copied onto each asset at
 * creation time and thereafter belong to that asset. Once an asset is
 * capitalised, the accounts its first journal used are the accounts its later
 * journals must use - the capitalisation entry, every depreciation entry and the
 * disposal entry all reference the same asset account, and changing it halfway
 * through would split one asset's cost across two accounts with no way to undo it.
 *
 * So the snapshot is taken on the asset row, and this table is the default the
 * snapshot is taken from. Changing a category afterwards affects only assets
 * created afterwards, which is the correct behaviour and is the reason a category
 * with capitalised assets can still be edited.
 *
 * WHY gain AND loss ARE SEPARATE COLUMNS
 *
 * They are on opposite sides of the profit and loss statement, they mean opposite
 * things to a reader of a disposal report, and a single "disposal_result_account"
 * column would make it possible to configure a category that books every disposal
 * - profitable or not - to whichever of the two flatter the number. Two nullable
 * columns, with the service refusing a category that has neither.
 *
 * accumulated_depreciation_account_id MUST BE A CONTRA ACCOUNT
 *
 * The schema cannot check account type or normal balance - those live on another
 * table - so TransactionAccountResolver::accumulatedDepreciation() checks both at
 * the request layer and again at posting time. That redundancy is the point: the
 * check is cheap and the consequence of skipping it is a balance sheet that
 * quietly inflates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | A human code, because a category is something a user looks up while
             | filling in an asset form. Unique per company rather than globally, so
             | two companies may both call their vehicle category "VEH".
             */
            $table->string('code', 50);

            $table->string('name', 150);

            $table->text('description')->nullable();

            /*
             | The depreciation configuration every asset of this category starts
             | with. Months rather than years because that is the unit straight-line
             | arithmetic needs to divide by, and because a life stated in years
             | cannot express the eighteen-month life of some equipment without a
             | fractional convention this phase has no rule for.
             */
            $table->unsignedSmallInteger('useful_life_months');

            /*
             | Free text, as on credit_debit_notes.note_type and
             | cash_bank_transactions.transaction_type: the enum is the
             | application's contract, the column only stores what it says.
             */
            $table->string('depreciation_method', 30)->default('STRAIGHT_LINE');

            /*
             | The five account references. Declared as plain unsigned big integers
             | with explicitly named foreign keys below rather than with
             | foreignId()->constrained(), because Laravel derives the constraint name
             | as {table}_{column}_foreign and MySQL rejects any identifier over 64
             | characters:
             |
             |   fixed_asset_categories_accumulated_depreciation_account_id_foreign
             |
             | is 66. Every constraint here is named in full by hand so that all five
             | read alike in information_schema and so that adding a sixth account
             | later does not silently produce a table that cannot be migrated at all.
             |
             | restrictOnDelete throughout: an account in use by a category must not be
             | deleted out from under it. Deactivation is how an account is retired,
             | which is what TransactionAccountResolver already checks for.
             */
            $table->unsignedBigInteger('asset_account_id');

            $table->unsignedBigInteger('accumulated_depreciation_account_id');

            $table->unsignedBigInteger('depreciation_expense_account_id');

            /*
             | Nullable, unlike the other three, and the asymmetry is deliberate.
             | A company that has never sold a company car has no gain-on-disposal
             | account, and forcing one to be configured would mean inventing an
             | account nobody will ever post to. A disposal only ever needs ONE of
             | the two - a gain when the proceeds exceed the carrying value, a loss
             | when they fall short, neither when they are equal - so requiring both
             | would forbid configuring only the one a company can actually use.
             |
             | nullOnDelete rather than restrictOnDelete, which is the rule for every
             | nullable account reference in this schema.
             */
            $table->unsignedBigInteger('gain_on_disposal_account_id')->nullable();

            $table->unsignedBigInteger('loss_on_disposal_account_id')->nullable();

            /*
             | Deactivation rather than deletion as the retirement mechanism, for the
             | reason Phase 4 established for accounts and Phase 10 for taxes: a
             | category that has been used cannot be destroyed, and a category that
             | has not can be deleted outright. The delete route distinguishes them.
             */
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'name']);

            /*
             | The one query the register makes on every row, and the one a listing
             | filtered by category makes. The unique indexes above already lead
             | with company_id, so this is the same column in a different order -
             | chosen because "every active category in this company" is asked far
             | more often than "the category named FOO".
             */
            $table->index(['company_id', 'is_active']);
        });

        /*
         * The five account constraints, named explicitly for the reason given at the
         * column declarations. Each is a single column against accounts.id, which is
         * what foreignId()->constrained() would have produced had the name fitted.
         */
        Schema::table('fixed_asset_categories', function (Blueprint $table) {
            $table->foreign('asset_account_id', 'fixed_asset_categories_asset_acc_fk')
                ->references('id')
                ->on('accounts')
                ->restrictOnDelete();

            $table->foreign(
                'accumulated_depreciation_account_id',
                'fixed_asset_categories_accum_dep_acc_fk'
            )
                ->references('id')
                ->on('accounts')
                ->restrictOnDelete();

            $table->foreign(
                'depreciation_expense_account_id',
                'fixed_asset_categories_dep_exp_acc_fk'
            )
                ->references('id')
                ->on('accounts')
                ->restrictOnDelete();

            $table->foreign('gain_on_disposal_account_id', 'fixed_asset_categories_gain_acc_fk')
                ->references('id')
                ->on('accounts')
                ->nullOnDelete();

            $table->foreign('loss_on_disposal_account_id', 'fixed_asset_categories_loss_acc_fk')
                ->references('id')
                ->on('accounts')
                ->nullOnDelete();
        });

        SchemaCheck::add(
            'fixed_asset_categories',
            'useful_life_months > 0',
            'fixed_asset_categories_life_check'
        );

        SchemaCheck::add(
            'fixed_asset_categories',
            "depreciation_method = 'STRAIGHT_LINE'",
            'fixed_asset_categories_method_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_categories');
    }
};
