<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 - one row per depreciation period per asset.
 *
 * THESE ROWS ARE POSTED FACTS, NOT SCHEDULED PLANS
 *
 * There is no pending and no scheduled state. A row exists if and only if a
 * depreciation charge has been posted to the ledger, because the two are written
 * in one transaction: JournalPostingService posts, FixedAssetService writes the
 * row, and either both commit or neither does.
 *
 * That is why there is no status column and no accumulated column, and it is the
 * reason an asset's accumulated depreciation needs no column on fixed_assets at
 * all - summing this table IS the posted total, with no unposted entries to exclude
 * and no cache to drift. A scheduled-but-unposted row would break exactly that
 * equality, which is why the concept does not exist here.
 *
 * A CONCURRENTLY-RUNNING ASSET CANNOT BE DOUBLE-CHARGEED
 *
 * The two unique constraints below are the whole defence, and they are both needed
 * because they catch different mistakes:
 *
 *   (fixed_asset_id, period_number)
 *       catches the ordinary race - two requests for "month 7" of the same asset at
 *       the same moment. Whoever commits second is refused by the index, not by a
 *       read that happened before the first commit.
 *
 *   (fixed_asset_id, period_start_date)
 *       catches the nastier one, and the reason period_number alone is not enough.
 *       A row can be inserted by hand, restored from a backup, or written by a
 *       future maintenance script with a period_number that is off by one; its
 *       period_start_date would still be the real first day of a month that already
 *       has a charge. Both constraints together make the period identified by
 *       either of its two natural keys, so neither can be duplicated by relabelling.
 *
 * The service also rejects out-of-order posts ("only the earliest unposted period
 * may be charged") and locks the asset row with FOR UPDATE before summing, but
 * neither substitutes for the index: a check made from a snapshot is a check made
 * before the other transaction committed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('fixed_asset_id')
                ->constrained('fixed_assets')
                ->cascadeOnDelete();

            /*
             | 1, 2, 3... over the asset's own life from depreciation_start_date.
             | NOT the financial year's period number, and not a calendar month
             | index: an asset capitalised on 15 March has period 1 running to
             | 14 April, and its period 7 is a different set of days than the same
             | asset's period 7 would be had it started in January.
             |
             | Unique with the asset, which is what makes "charge this asset for
             | month 7" a question with a yes/no answer rather than a search.
             */
            $table->unsignedSmallInteger('period_number');

            /*
             | The first and last day of the period, derived from the asset's
             | depreciation_start_date rather than from the company's financial year
             | and not supplied by the client.
             |
             | period_end_date is inclusive. The last day of period 1 of an asset
             | capitalised on 15 March is 14 April, and the second period starts on
             | 15 April - so period_end_date is not a redundant copy of the next
             | row's period_start_date but the boundary itself, which is what a
             | depreciation report groups on.
             */
            $table->date('period_start_date');

            $table->date('period_end_date');

            /*
             | The charge for this period, computed by the service from
             * (original_cost - salvage_value) / useful_life_months and stored. It is
             | a stored figure because it is the amount of a specific journal line
             | that has already been posted, not a running total: the journal is the
             | authority for the ledger effect and this column is a faithful record of
             | what was sent to it.
             |
             | The final period may differ from the standard monthly charge, by a
             | fraction of a currency unit, so that the periods sum exactly to the
             | depreciable base. Rounding each period independently and letting the
             | last one absorb the difference is the only approach that does not leave
             * a residual amount permanently undepreciated.
             */
            $table->decimal('amount', 20, 4);

            /*
             | The journal this charge produced, and the audit record of posting it.
             | Same nullOnDelete pointer and same "from the authenticated user, never
             | from a payload" rule as every other document in this schema.
             */
            $table->foreignId('journal_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('posted_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('posted_at');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            /*
             * Both keys for one period. See the header.
             */
            $table->unique(['fixed_asset_id', 'period_number'], 'fixed_asset_deprecations_period_unique');

            $table->unique(
                ['fixed_asset_id', 'period_start_date'],
                'fixed_asset_deprecations_start_unique'
            );

            /*
             * The asset's depreciation history in date order, which is both the
             * schedule endpoint's query and the disposal service's "how much has
             * this asset been written down" sum.
             */
            $table->index(['company_id', 'period_start_date']);

            $table->index('journal_id');
        });

        /*
         * amount > 0.
         *
         * The last period of an asset whose monthly charge rounds to zero at this
         * precision would be a zero amount, and a zero-amount journal line is
         * refused by JournalService anyway ("a journal line must have an amount
         * greater than zero on one side"). Rather than post a journal that fails
         * structural validation, the service stops the schedule when the remaining
         * depreciable balance is exhausted, and this constraint makes a zero row
         * impossible even if one is written by another path.
         */
        SchemaCheck::add(
            'fixed_asset_depreciations',
            'amount > 0',
            'fixed_asset_deprecations_amount_check'
        );

        /*
         * period_number > 0, and period_end_date >= period_start_date.
         *
         * Period zero would be "the depreciation before the asset started", and an
         * end before its start is a period that runs backwards. Neither can be
         * produced by the service, which derives both dates from the asset's
         * depreciation_start_date; both are here so that a hand-written row cannot
         * produce a schedule report that silently omits a month.
         */
        SchemaCheck::add(
            'fixed_asset_depreciations',
            'period_number > 0',
            'fixed_asset_deprecations_number_check'
        );

        SchemaCheck::add(
            'fixed_asset_depreciations',
            'period_end_date >= period_start_date',
            'fixed_asset_deprecations_dates_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
    }
};
