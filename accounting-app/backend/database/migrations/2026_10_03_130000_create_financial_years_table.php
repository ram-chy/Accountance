<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_years', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 255);

            /*
            | Inclusive date range, exactly as accounting_periods already stores
            | its own. A financial year is the same kind of fact: the first day
            | and the last day belong to it. Treating the end as exclusive would
            | make "2028-03-31" fall outside the year that contains it, which is
            | the off-by-one this whole phase exists to prevent.
            */
            $table->date('start_date');
            $table->date('end_date');

            /*
            | OPEN/CLOSED only. A financial year is never a draft: it is created
            | as a dated container and its usability is decided by whether its
            | periods are closed, not by a lifecycle of its own. Adding DRAFT or
            | ACTIVE here would be a state nothing can reach.
            */
            $table->string('status', 20)->default('OPEN');

            /*
            | Audit for the close. created_by records who set the year up;
            | closed_by/closed_at record the accounting-control act, which is
            | the one that needs to be attributable. Mirrors journals.created_by
            | and journals.posted_by/posted_at so the two are read the same way.
            |
            | No "reopened_by"/"reopened_at": Phase 8 deliberately does not
            | reopen financial years, so a column for it would never be written.
            */
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('closed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            /*
            | One financial-year name per company. The same rationale as
            | accounting_periods_company_id_name_unique: two years called
            | "2027-2028" in one company is always a mistake, and a duplicated
            | form submission is the usual cause.
            */
            $table->unique(['company_id', 'name']);

            /*
            | "Which financial year owns this date" and "which years may this
            | company post into" are the two lookups Phase 8 introduces, and
            | both are range-then-filter over the same small table - a company's
            | fiscal calendar is a handful of rows, not a table worth scanning.
            */
            $table->index(['company_id', 'start_date', 'end_date']);

            $table->index(['company_id', 'status']);
        });

        SchemaCheck::add(
            'financial_years',
            'end_date >= start_date',
            'financial_years_date_order_check'
        );

        SchemaCheck::add(
            'financial_years',
            "status in ('OPEN','CLOSED')",
            'financial_years_status_check'
        );

        /*
        | Deliberately no check tying status = 'CLOSED' to closed_by/closed_at being
        | set. MySQL rejects a CHECK over a column that also carries a foreign key
        | with a referential action (error 3823): `closed_by` is ON DELETE SET NULL
        | so that deleting a user does not cascade away the year, and that is
        | incompatible with testing the column in a constraint. The pairing is
        | enforced in FinancialYearService::close() instead, which is also the only
        | writer of `status`. Documented in PHASE_8_REPORT.md.
        */

        $this->attachExistingPeriodsToFinancialYears();
    }

    /**
     * Give every period that predates Phase 8 the financial year that contains it.
     *
     * The new financial_year_id is nullable precisely because periods already
     * exist and cannot be retrofitted with an id that does not exist yet. Rather
     * than leave them orphaned - which would mean a period that belongs to no
     * year, and therefore a year that could be closed while periods it does not
     * know about are still open - each existing period is assigned the year its
     * own start date falls in, using the same anchor rule the generator uses.
     *
     * Derived, not invented: the fiscal year of a date is a fact about the
     * configured calendar, not a business decision. A period is never moved or
     * re-dated here, and nothing is deleted.
     */
    private function attachExistingPeriodsToFinancialYears(): void
    {
        $startMonth = (int) config('accounting.fiscal_year.start_month', 4);

        $periods = DB::table('accounting_periods')
            ->select(['id', 'company_id', 'start_date'])
            ->orderBy('id')
            ->get();

        foreach ($periods as $period) {
            $year = $this->financialYearFor($period->company_id, $period->start_date, $startMonth);

            DB::table('accounting_periods')
                ->where('id', $period->id)
                ->update(['financial_year_id' => $year]);
        }
    }

    /**
     * The id of the financial year containing a date, created if absent.
     *
     * Months are counted from start_date to the same month one year later, so a
     * start month of 4 puts 2027-03-31 in the year that began 2026-04-01 and
     * 2027-04-01 in the next one - the boundary lands on the first of the month
     * rather than in the middle of it.
     */
    private function financialYearFor(int $companyId, string $date, int $startMonth): int
    {
        $day = Carbon::parse($date)->startOfDay();

        $start = $day->copy()->startOfMonth()->month($startMonth);

        if ($start->greaterThan($day)) {
            $start = $start->subYearNoOverflow();
        }

        $end = $start->copy()->addYearNoOverflow()->subDay();

        $existing = DB::table('financial_years')
            ->where('company_id', $companyId)
            ->whereDate('start_date', $start->toDateString())
            ->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        return (int) DB::table('financial_years')->insertGetId([
            'company_id' => $companyId,
            'name' => $start->format('Y').'-'.$end->format('Y'),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_years');
    }
};
