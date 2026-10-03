<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 - the fixed asset register.
 *
 * ONE ROW IS ONE ASSET THE COMPANY OWNS, from its acquisition to its disposal.
 *
 * NO ACCUMULATED DEPRECIATION COLUMN, AND THAT IS THE POINT
 *
 * The obvious design stores `accumulated_depreciation` here and increments it per
 * period. It would also be a stored accounting balance, which this application's
 * architecture does not have: Phase 4 made the posted ledger the only authority
 * for what an account's balance is, and Phase 6 builds every statement from the
 * ledger rather than from a cached figure.
 *
 * The dependency that produces the figure is not even needed here. A row in
 * fixed_asset_depreciations is created in the same transaction that posts its
 * journal, so "sum of this asset's depreciation rows" is *already* "sum of its
 * posted depreciation charges" - there is no unposted depreciation to exclude and
 * no cache to fall out of step with the ledger. One source, not two.
 *
 * The cost is a SUM per asset per schedule read, which the index on
 * (fixed_asset_id, period_number) serves directly. That is the right trade: it
 * buys the elimination of a class of bug in which a summary and the ledger
 * disagree and nothing detects it.
 *
 * WHY DATES ARE THREE, NOT ONE
 *
 *   acquisition_date        when the company paid for it or took it on credit
 *   depreciation_start_date when capitalised - the first day of period 1
 *   disposed_at             when it left (see fixed_asset_disposals)
 *
 * acquisition_date and depreciation_start_date are usually the same day and can
 * legitimately differ: an asset bought in March and capitalised in April has an
 * acquisition date in March. Collapsing them into one column would make it
 * impossible to record the month the asset was paid for without also asserting
 * that it started depreciating then, which is not true of assets capitalised at
 * the end of a month or in a batch.
 *
 * THE ACCOUNT SNAPSHOT
 *
 * Five account columns, copied from the category on creation and never read from
 * it again. This is the same "copy, do not join" decision Phase 10 made for tax
 * rates on document lines, for the same reason: the capitalisation entry, the
 * hundred-and-twenty depreciation entries and the disposal entry must all post
 * cost to the SAME account. An asset whose account came from a live join could
 * have its first thirty months of cost in one account and the rest in another,
 * with nothing recording that this happened.
 *
 * Which is also why a category can be edited after assets exist: the edit changes
 * the default for the next asset, not the history of the ones already written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | Server-allocated from document_number_sequences, type FIXED_ASSET,
             | prefixed FA-. One sequence for the whole register, because there is
             | one table - see DocumentNumberType. Quoted on asset labels and in
             | insurance schedules, so it is sequential per company rather than
             | random, and unique with company_id rather than globally so two
             | companies may each start at FA-000001.
             */
            $table->string('asset_number', 50);

            $table->foreignId('fixed_asset_category_id')
                ->constrained('fixed_asset_categories')
                ->restrictOnDelete();

            $table->string('name', 150);

            $table->text('description')->nullable();

            /*
             | Physical detail only. Serial numbers and registration plates identify
             | the object for maintenance and insurance purposes and play no part in
             | any entry; the brief defers physical asset tracking, so this table
             | records what the accounting needs and nothing more.
             */
            $table->string('serial_number', 100)->nullable();

            $table->string('supplier_reference', 100)->nullable();

            /*
             | When the company paid for the asset or took it on credit. The date the
             | money moved, which is the date a reader of the register means by "when
             | did we buy this" - and it is NOT the date capitalised, because the two
             | can differ and the money-moving one is the one an auditor asks for.
             */
            $table->date('acquisition_date');

            /*
             | What the asset cost, and what it will be worth at the end of its life.
             | Both are the depreciable base's inputs and both are copied from the
             | purchase - neither is derived, and neither changes once capitalised.
             |
             | salvage_value is what the company expects to recover, not what it
             | expects to sell the asset for. That is the distinction that makes a
             | disposal able to produce a loss even though salvage was booked in
             | advance, and it is why the disposal service compares proceeds against
             | the CARRYING value rather than against salvage.
             */
            $table->decimal('original_cost', 20, 4);

            $table->decimal('salvage_value', 20, 4)->default(0);

            /*
             | Snapshot of the category's depreciation configuration, for the same
             | reason as the account columns: an asset of five years bought this year
             | depreciates over five years even if its category is later redefined to
             | ten. Changing the category must not restate the depreciation already
             | posted, and storing the schedule on the asset makes that impossible
             | rather than merely unlikely.
             */
            $table->unsignedSmallInteger('useful_life_months');

            $table->string('depreciation_method', 30)->default('STRAIGHT_LINE');

            /*
             | The first day of depreciation period 1. Period N then runs from this
             | date to the same day N-1 months later, which is what makes a period a
             | calendar month of the asset's own cycle rather than of the company's
             | financial year - an asset capitalised on the 15th has a period 1 that
             | is not a calendar month, and that is correct, because half a month of
             | ownership is half a month of depreciation.
             */
            $table->date('depreciation_start_date');

            /*
             | DRAFT / ACTIVE / FULLY_DEPRECIATED / DISPOSED. See FixedAssetStatus for
             | why this is not TransactionStatus - chiefly that an asset stays in the
             | ledger for years after it is capitalised, so there is no POSTED state
             | for it to move into.
             */
            $table->string('status', 25)->default('DRAFT');

            /*
             | The capitalisation entry. journal_id is the same nullOnDelete pointer
             | every other document in this schema carries; capitalised_at and
             | capitalised_by are the audit record, taken from the authenticated user
             | and never from a payload.
             */
            $table->foreignId('journal_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamp('capitalised_at')->nullable();

            $table->foreignId('capitalised_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            /*
             | CASH / SUPPLIER_CREDIT. What the credit side of the capitalisation entry
             | is, and therefore which kind of account is acceptable there. Not
             | inferred from the account, and not free-text: the enum is the
             | application's contract and this column stores only what it says.
             */
            $table->string('acquisition_method', 30)->default('CASH');

            /*
             | THE MONEY SIDE OF THE ACQUISITION.
             |
             | The capitalisation entry is one of two, and which one is decided by
             | acquisition_method rather than by the account:
             |
             |   CASH             Dr Fixed Asset      / Cr this account (cash/bank)
             |   SUPPLIER_CREDIT  Dr Fixed Asset      / Cr this account (payable)
             |
             | So the account and the method are two fields that must agree, and the
             | resolver checks that they do - cashBank() for the first, payable() for
             | the second - rather than trusting whichever arrived first. A client
             | that sent method CASH with a liability account is refused, because the
             | question being asked of the account is one about the account, not a
             | preference the user is expressing here.
             |
             | On the asset rather than on the category because it is genuinely
             | per-asset: a company buying a van on credit and a laptop outright in
             | the same month is entirely ordinary, and both are vehicles category
             | members.
             */
            $table->foreignId('acquisition_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            /*
             | The account debited on capitalisation and credited on disposal.
             | ASSET, checked by TransactionAccountResolver::fixedAsset() rather than
             | here, because account type lives on another table.
             */
            $table->foreignId('asset_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            /*
             | Credited by every depreciation entry, debited by the disposal to
             * remove the asset's accumulated depreciation in one line. Must be
             | CREDIT-normal as well as ASSET - see
             * TransactionAccountResolver::accumulatedDepreciation().
             */
            $table->foreignId('accumulated_depreciation_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->foreignId('depreciation_expense_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            /*
             | Nullable, as on the category. A disposal needs only the one that
             | matches its result, so an asset with no gain account can still be
             * disposed of at a loss.
             */
            $table->foreignId('gain_on_disposal_account_id')
                ->nullable()
                ->constrained('accounts')
                ->nullOnDelete();

            $table->foreignId('loss_on_disposal_account_id')
                ->nullable()
                ->constrained('accounts')
                ->nullOnDelete();

            /*
             | Set when the asset is disposed. The disposal row itself
             | (fixed_asset_disposals) holds the date, proceeds and gain or loss -
             | these two columns exist so a listing of the register can filter
             | "current assets only" or "what went last year" without joining the
             * disposals table on every row.
             */
            $table->timestamp('disposed_at')->nullable();

            $table->foreignId('disposed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            /*
             | Set when the last depreciation period completes, so "is this asset
             | fully written down" can be answered from the register without summing
             | its depreciation rows. Unlike accumulated_depreciation this is not an
             | accounting figure but a state marker the depreciation service owns,
             * and it is derived from the rows rather than trusted independently.
             */
            $table->timestamp('fully_depreciated_at')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'asset_number']);
        });

        /*
         * original_cost > 0.
         *
         * A zero-cost asset has no depreciable base and no journal worth posting -
         * the capitalisation entry would be two lines of nothing, which
         * JournalService rejects outright for being unbalanced at zero. Refusing it
         * at the column is friendlier than accepting it and failing three requests
         * later.
         *
         * salvage_value >= 0, because a negative residual value means the asset is
         * expected to cost money to get rid of. That is a real possibility in
         * principle and it is not what this field means; where it applies, the
         * additional cost belongs in the disposal, which is where it actually lands.
         *
         * salvage_value <= original_cost, because a residual value above cost implies
         * the asset gains value while it depreciates, which straight-line arithmetic
         * cannot express - the base is negative, and every period would be a credit
         * to the expense account.
         *
         * useful_life_months > 0, for the same reason as on the category: dividing by
         * zero is not a rounding question.
         */
        SchemaCheck::add(
            'fixed_assets',
            'original_cost > 0',
            'fixed_assets_cost_check'
        );

        SchemaCheck::add(
            'fixed_assets',
            'salvage_value >= 0 and salvage_value <= original_cost',
            'fixed_assets_salvage_check'
        );

        SchemaCheck::add(
            'fixed_assets',
            'useful_life_months > 0',
            'fixed_assets_life_check'
        );

        SchemaCheck::add(
            'fixed_assets',
            "depreciation_method = 'STRAIGHT_LINE'",
            'fixed_assets_method_check'
        );

        SchemaCheck::add(
            'fixed_assets',
            "acquisition_method in ('CASH', 'SUPPLIER_CREDIT')",
            'fixed_assets_acquisition_check'
        );

        /*
         * The status a row may hold, and - the part that earns its place - the
         * states each one requires to be consistent with.
         *
         * Without these, a row could claim to be DISPOSED with no disposed_at, or
         * ACTIVE with no capitalisation timestamp, and nothing would notice: all of
         * these columns are nullable, and nullable columns cannot be tied to each
         * other with foreign keys. The service sets them correctly; this makes it
         * impossible for it to set them incorrectly.
         *
         * WHAT IS DELIBERATELY ABSENT: a constraint requiring journal_id to be
         * present on any non-draft row.
         *
         * That would be the natural fourth rule, and MySQL will not accept it -
         * error 3823, "column cannot be used in a check constraint: needed in a
         * foreign key constraint referential action". A column carrying ON DELETE
         * SET NULL cannot appear in a CHECK, because dropping the referencing row
         * would change the very value being tested. journal_id is nullOnDelete for
         * the same reason every other document in this schema uses that action, so
         * the constraint is not merely unwelcome, it is uncreatable.
         *
         * This is the same split Phase 11 arrived at on credit_debit_notes, where
         * the posting invariant is proved for posted_by and posted_at and the journal
         * half is left to the service writing all four in one statement. It was
         * found by running the migration, not by reading about it. So the schema
         * proves two of the four halves here, and FixedAssetService guarantees all
         * four.
         */
        SchemaCheck::add(
            'fixed_assets',
            "status in ('DRAFT', 'ACTIVE', 'FULLY_DEPRECIATED', 'DISPOSED')",
            'fixed_assets_status_check'
        );

        SchemaCheck::add(
            'fixed_assets',
            "(status <> 'DISPOSED') or disposed_at is not null",
            'fixed_assets_disposed_requires_date_check'
        );

        /*
         * capitalised_at is required exactly when the asset is off DRAFT. This one
         * IS expressible, because capitalised_at is a plain nullable timestamp with
         * no foreign key - the restriction above applies to journal_id, not to the
         * audit columns beside it.
         *
         * capitalised_by is deliberately left out of the same expression for the
         * same reason: it carries restrictOnDelete, so it would hit error 3823 too.
         */
        SchemaCheck::add(
            'fixed_assets',
            "(status = 'DRAFT') or (capitalised_at is not null)",
            'fixed_assets_capitalised_requires_date_check'
        );

        /*
         * The query shapes, in the order they matter:
         *
         * 1. (company_id, status)            - the register, filtered to what is
         *                                      still owned, and the guard the
         *                                      depreciation and disposal services run
         *                                      on every write.
         * 2. (company_id, depreciation_start_date)
         *                                   - "which assets should be depreciating
         *                                      this month", which is what the
         *                                      monthly run asks.
         * 3. (company_id, fixed_asset_category_id)
         *                                   - the register grouped by category, and
         *                                      the depreciation report.
         * 4. (company_id, asset_number)     - already unique, and the lookup a
         *                                      user performs from an asset label.
         */
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'depreciation_start_date']);
            $table->index(['company_id', 'fixed_asset_category_id']);
            $table->index(['company_id', 'acquisition_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
