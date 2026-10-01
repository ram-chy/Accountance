<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table) {
            /*
            | Placed after the table exists and before anything reads it. Nullable
            | because the create_financial_years migration backfills the rows that
            | already exist; new periods always set it, and the application
            | refuses a period without one. A NOT NULL column here would fail on
            | the upgrade path for any company that already had periods.
            */
            $table->foreignId('financial_year_id')
                ->nullable()
                ->after('company_id')
                ->constrained('financial_years')
                ->nullOnDelete();

            /*
            | Close audit. The period has always moved between states through
            | AccountingPeriodService, which is the only writer of `status`; from
            | Phase 8 it also records who closed it and when. Without these, a
            | closed period answers "no" to every posting attempt but nobody can
            | say who decided that.
            |
            | Reopen audit, kept as a separate pair rather than by leaving
            | closed_by/closed_at in place. The brief requires reopening to record
            | who did it and when, and that record has to survive the transition:
            | a period that says "open" while carrying a closer is a period whose
            | attribution cannot be trusted for either state. So reopen() clears the
            | close pair and stamps the reopen pair, and each pair means "the last
            | actor of this kind". That is the smallest honest design - it is two
            | nullable columns, not an audit-history table, and it does not attempt
            | to record how many times a period has been cycled.
            */
            $table->foreignId('closed_by')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('closed_at')->nullable()->after('closed_by');

            $table->foreignId('reopened_by')
                ->nullable()
                ->after('closed_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reopened_at')->nullable()->after('reopened_by');

            /*
            | The period list is now grouped by year before it is filtered by
            | status, and generation reads a year and checks which months it
            | already has. Both are "company, year, dates" lookups.
            */
            $table->index(['company_id', 'financial_year_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->dropForeign(['financial_year_id']);
            $table->dropForeign(['closed_by']);
            $table->dropForeign(['reopened_by']);
            $table->dropIndex(['company_id', 'financial_year_id', 'start_date']);
            $table->dropColumn(['financial_year_id', 'closed_by', 'closed_at', 'reopened_by', 'reopened_at']);
        });
    }
};
