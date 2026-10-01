<?php

namespace App\Services\Accounting;

use App\Enums\FinancialYearStatus;
use App\Enums\PeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Financial-year creation, period generation and year closing.
 *
 * A financial year is a dated container and nothing more. It holds no amounts,
 * no balances and no cached reporting data, and closing it writes no journal:
 * JournalReportService::retainedEarnings() already derives retained earnings from
 * posted lines through a date, so a year-end closing entry would be counted a
 * second time on the balance sheet. Closing a year is therefore purely a
 * statement that the year is finished, and the only thing that moves money in
 * this system is JournalPostingService.
 *
 * Period generation lives here rather than in AccountingPeriodService because it
 * is the year that owns the set of periods: the generator's whole job is to
 * walk the year's own start_date to its own end_date. It still writes periods
 * through the same overlap-checked path a hand-created period takes, so a
 * generated period and a typed one are the same kind of row.
 */
class FinancialYearService
{
    /**
     * Create a financial year, rejecting any range that overlaps an existing one.
     *
     * Overlap is checked inside the transaction and then again by the unique
     * index on (company_id, name): the application check cannot see another
     * transaction's uncommitted row, so the index is what actually settles a
     * race between two simultaneous requests.
     *
     * @param  array{name: string, start_date: string, end_date: string, financial_year_id?: int|null}  $data
     *
     * @throws ValidationException
     */
    public function create(Company $company, User $actor, array $data): FinancialYear
    {
        return DB::transaction(function () use ($company, $actor, $data) {
            $start = Carbon::parse($data['start_date'])->startOfDay();
            $end = Carbon::parse($data['end_date'])->startOfDay();

            if ($end->lessThan($start)) {
                throw ValidationException::withMessages([
                    'end_date' => 'The financial year end date must not be before its start date.',
                ]);
            }

            $this->assertNoOverlap($company, $start, $end);

            /*
             * forceFill, not create(): company_id, status, created_by and the
             * close audit are all decided by the authenticated context or by the
             * lifecycle, never by the request body. Passing them to create()
             * would have $fillable drop them silently and the NOT NULL
             * company_id would then fail the insert.
             */
            $year = new FinancialYear([
                'name' => $data['name'],
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ]);

            $year->forceFill([
                'company_id' => $company->getKey(),
                'status' => FinancialYearStatus::Open->value,
                'created_by' => $actor->getKey(),
            ]);

            try {
                $year->save();
            } catch (QueryException $e) {
                /*
                 * Only the (company_id, name) index is translated. A blanket
                 * catch would answer "name already in use" for an unrelated
                 * failure and send the user to rename a name that was never the
                 * problem.
                 */
                if (! $this->isDuplicateYearName($e)) {
                    throw $e;
                }

                throw ValidationException::withMessages([
                    'name' => 'This financial year name is already in use for the selected company.',
                ]);
            }

            return $year;
        });
    }

    /**
     * Generate the monthly accounting periods belonging to a financial year.
     *
     * Safe to call repeatedly. Each month is checked against the periods the
     * company already has for that year before it is inserted, so a second call
     * over a fully generated year creates nothing and reports it. A month that
     * already exists as a differently-named period is left alone rather than
     * replaced: an operator who renamed "January 2027" to something else has
     * made a decision, and generation undoing it would be the generator
     * overwriting accounting history.
     *
     * Months are derived by walking from the year's own start_date one month at a
     * time and stopping at its end_date, which means the boundaries come from
     * the year rather than from the fiscal-calendar config. A year whose dates
     * do not begin on the first of a month therefore yields whatever months its
     * own range actually covers, and nothing outside the year is ever created.
     *
     * @return array<int, AccountingPeriod> the periods that exist after the call,
     *                                      in date order
     *
     * @throws ValidationException
     */
    public function generatePeriods(FinancialYear $year): array
    {
        return DB::transaction(function () use ($year) {
            $existing = $year->periods()
                ->orderBy('start_date')
                ->get(['id', 'name', 'start_date']);

            // Keyed by month so a repeat call recognises its own earlier work.
            $taken = [];

            foreach ($existing as $period) {
                $taken[Carbon::parse($period->start_date)->format('Y-m')] = true;
            }

            $start = Carbon::parse($year->start_date)->startOfDay();
            $end = Carbon::parse($year->end_date)->startOfDay();

            $month = $start->copy()->startOfMonth();

            while ($month->lessThanOrEqualTo($end)) {
                // Clip the month to the year: a year that starts on the 15th has a
                // first period of the 15th to the 31st, and one that ends on the
                // 10th has a last period of the 1st to the 10th.
                $periodStart = $month->greaterThan($start) ? $month->copy() : $start->copy();
                $periodEnd = $month->copy()->endOfMonth()->lessThan($end)
                    ? $month->copy()->endOfMonth()
                    : $end->copy();

                $key = $month->format('Y-m');

                if (! isset($taken[$key])) {
                    $period = new AccountingPeriod([
                        'name' => $month->format('F Y'),
                        'start_date' => $periodStart->toDateString(),
                        'end_date' => $periodEnd->toDateString(),
                    ]);

                    $period->forceFill([
                        'company_id' => $year->company_id,
                        'financial_year_id' => $year->getKey(),
                        'status' => PeriodStatus::Open->value,
                    ]);

                    /*
                     * Overlap is asserted rather than assumed. Generated months
                     * are consecutive and cannot collide with each other, but this
                     * year may sit alongside a hand-created period that reaches
                     * into one of its months, and silently writing an overlapping
                     * period would leave the company unable to resolve a date to
                     * exactly one period. That is a real configuration mistake,
                     * so it is reported instead of being papered over.
                     */
                    $this->assertPeriodRangeIsFree($year, $periodStart, $periodEnd);

                    $period->save();

                    $taken[$key] = true;
                }

                $month = $month->copy()->addMonthNoOverflow();
            }

            return $year->periods()->orderBy('start_date')->get()->all();
        });
    }

    /**
     * Close a financial year.
     *
     * Only permitted once every period of the year is closed. Closing the
     * periods is the substantive act - it is what stops new postings - and the
     * year close records that the whole span is finished. Closing a year with an
     * open month inside it would leave a date that the year says is finished and
     * the period says is still live, which is the contradiction this check exists
     * to prevent.
     *
     * No journal is written. See the class docblock for why.
     *
     * @throws ValidationException
     */
    public function close(FinancialYear $year, User $actor): FinancialYear
    {
        return DB::transaction(function () use ($year, $actor) {
            $fresh = FinancialYear::query()
                ->whereKey($year->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status->isClosed()) {
                throw ValidationException::withMessages([
                    'financial_year' => 'This financial year is already closed.',
                ]);
            }

            $periodCount = $fresh->periods()->count();

            if ($periodCount === 0) {
                throw ValidationException::withMessages([
                    'financial_year' => 'A financial year with no accounting periods cannot be closed. Generate its periods first.',
                ]);
            }

            $openCount = $fresh->periods()->where('status', 'OPEN')->count();

            if ($openCount > 0) {
                throw ValidationException::withMessages([
                    'financial_year' => 'All periods in this financial year must be closed before the year can be closed. '
                        ."{$openCount} period(s) are still open.",
                ]);
            }

            $fresh->forceFill([
                'status' => FinancialYearStatus::Closed->value,
                'closed_by' => $actor->getKey(),
                'closed_at' => now(),
            ])->save();

            return $fresh;
        });
    }

    /**
     * The financial year containing a date for this company, creating it if the
     * company's calendar has not been set up for that year yet.
     *
     * Auto-creation is what makes the rest of the phase tolerant of a company
     * that has periods but never defined a fiscal year: rather than refusing to
     * post because the container is missing, the container is derived from the
     * configured calendar. Deriving it is not inventing a business rule - the
     * fiscal year of a date follows from start_month.
     *
     * The date is not mutated by this call. It is the container that is created.
     */
    public function resolveOrCreateForDate(Company $company, Carbon $date): FinancialYear
    {
        [$start, $end] = $this->fiscalRangeFor($date);

        $existing = $this->findForDate($company, $date);

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($company, $start, $end) {
            // Re-check inside the transaction: two postings on the first ever
            // date of a new year both reach this point, and the unique index on
            // name is what stops the second from creating a duplicate year.
            $racing = FinancialYear::query()
                ->where('company_id', $company->getKey())
                ->whereDate('start_date', $start->toDateString())
                ->first();

            if ($racing !== null) {
                return $racing;
            }

            $year = new FinancialYear([
                'name' => $start->format('Y').'-'.$end->format('Y'),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ]);

            $year->forceFill([
                'company_id' => $company->getKey(),
                'status' => FinancialYearStatus::Open->value,
            ]);

            try {
                $year->save();
            } catch (QueryException $e) {
                if (! $this->isDuplicateYearName($e)) {
                    throw $e;
                }

                return FinancialYear::query()
                    ->where('company_id', $company->getKey())
                    ->whereDate('start_date', $start->toDateString())
                    ->firstOrFail();
            }

            return $year;
        });
    }

    /**
     * The financial year containing a date for this company, or null.
     */
    public function findForDate(Company $company, Carbon $date): ?FinancialYear
    {
        $day = $date->copy()->startOfDay();

        return FinancialYear::query()
            ->where('company_id', $company->getKey())
            ->whereDate('start_date', '<=', $day->toDateString())
            ->whereDate('end_date', '>=', $day->toDateString())
            ->first();
    }

    /**
     * The fiscal year range a date belongs to, from the configured start month.
     *
     * The year runs from the first of start_month to the last day of the same
     * month one year later, so with start_month 4 the year containing
     * 2027-03-31 begins 2026-04-01 and the one containing 2027-04-01 begins
     * 2027-04-01. The boundary falls on the first of a month, never inside one.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function fiscalRangeFor(Carbon $date): array
    {
        $startMonth = (int) config('accounting.fiscal_year.start_month', 4);

        $start = $date->copy()->startOfDay()->startOfMonth()->month($startMonth);

        if ($start->greaterThan($date)) {
            $start = $start->subYearNoOverflow();
        }

        return [$start->copy(), $start->copy()->addYearNoOverflow()->subDay()];
    }

    /**
     * Update a financial year's name and dates.
     *
     * A closed year is immutable, for the same reason a closed period is: moving
     * the dates of a year that has been declared finished would change which
     * dates the close covers without anybody closing them.
     *
     * @param  array{name?: string, start_date?: string, end_date?: string}  $data
     *
     * @throws ValidationException
     */
    public function update(FinancialYear $year, array $data): FinancialYear
    {
        if ($year->status->isClosed()) {
            throw ValidationException::withMessages([
                'financial_year' => 'A closed financial year cannot be modified.',
            ]);
        }

        $start = isset($data['start_date'])
            ? Carbon::parse($data['start_date'])->startOfDay()
            : $year->start_date->startOfDay();

        $end = isset($data['end_date'])
            ? Carbon::parse($data['end_date'])->startOfDay()
            : $year->end_date->startOfDay();

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages([
                'end_date' => 'The financial year end date must not be before its start date.',
            ]);
        }

        // A year cannot be moved to swallow a different year's dates either.
        $this->assertNoOverlap($year->company, $start, $end, excludingId: $year->getKey());

        /*
         * Its periods must still fit inside the new range. Shrinking a year so
         * that a period now starts before it would produce a period belonging to
         * a year that does not contain it, and the resolver would then have two
         * candidate years for the same date.
         */
        $outside = $year->periods()
            ->where(function ($query) use ($start, $end) {
                $query->whereDate('start_date', '<', $start->toDateString())
                    ->orWhereDate('end_date', '>', $end->toDateString());
            })
            ->orderBy('start_date')
            ->first(['name', 'start_date', 'end_date']);

        if ($outside !== null) {
            throw ValidationException::withMessages([
                'end_date' => 'This financial year cannot be shortened while it contains periods outside the new range. '
                    ."Period [{$outside->name}] would fall outside it.",
            ]);
        }

        try {
            $year->forceFill(array_filter([
                'name' => $data['name'] ?? null,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ], fn ($value) => $value !== null))->save();
        } catch (QueryException $e) {
            if (! $this->isDuplicateYearName($e)) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'name' => 'This financial year name is already in use for the selected company.',
            ]);
        }

        return $year->refresh();
    }

    /**
     * Reject a range that intersects an existing financial year of this company.
     *
     * Intersection is inclusive on both ends, matching AccountingPeriodService, so
     * back-to-back fiscal years do not collide.
     *
     * @throws ValidationException
     */
    private function assertNoOverlap(
        Company $company,
        Carbon $start,
        Carbon $end,
        ?int $excludingId = null
    ): void {
        $query = FinancialYear::query()
            ->where('company_id', $company->getKey())
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString());

        if ($excludingId !== null) {
            $query->whereKeyNot($excludingId);
        }

        $clash = $query->first(['name', 'start_date', 'end_date']);

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'start_date' => "These dates overlap the existing financial year [{$clash->name}] "
                    .'('.Carbon::parse($clash->start_date)->toDateString().' - '
                    .Carbon::parse($clash->end_date)->toDateString().').',
            ]);
        }
    }

    /**
     * Reject a period range that would collide with a period the company already
     * has outside this financial year.
     *
     * @throws ValidationException
     */
    private function assertPeriodRangeIsFree(FinancialYear $year, Carbon $start, Carbon $end): void
    {
        $clash = AccountingPeriod::query()
            ->where('company_id', $year->company_id)
            ->where('financial_year_id', '!=', $year->getKey())
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->first(['name', 'start_date', 'end_date']);

        if ($clash === null) {
            return;
        }

        throw ValidationException::withMessages([
            'financial_year' => "Generating these periods would overlap the existing period [{$clash->name}] "
                .'('.Carbon::parse($clash->start_date)->toDateString().' - '
                .Carbon::parse($clash->end_date)->toDateString().'), '
                .'which belongs to a different financial year.',
        ]);
    }

    /**
     * Did this query exception come from the (company_id, name) unique index?
     *
     * MySQL reports the constraint by name and is the only engine this schema
     * targets, so the name is matched directly rather than parsed out of a
     * driver-specific message.
     */
    private function isDuplicateYearName(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'financial_years_company_id_name_unique')
            || str_contains($message, 'financial_years.name');
    }
}
