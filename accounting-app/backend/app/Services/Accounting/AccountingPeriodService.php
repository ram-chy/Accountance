<?php

namespace App\Services\Accounting;

use App\Enums\PeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creation, validation and closing of accounting periods.
 *
 * The period model is intentionally thin. There is no lock-date, no
 * year-end-close, no automatic period creation from a fiscal calendar - the
 * spec asks for a simple OPEN/CLOSED date range and none of that. What is not
 * thin is the enforcement: a date must land in exactly one open period of the
 * company's own before a journal may post.
 */
class AccountingPeriodService
{
    /**
     * Create a period, rejecting any date range that overlaps an existing one.
     *
     * @throws ValidationException
     */
    public function create(Company $company, array $data): AccountingPeriod
    {
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages([
                'end_date' => 'The period end date must not be before its start date.',
            ]);
        }

        $this->assertNoOverlap($company, $start, $end);

        /*
         * forceFill, not create(): company_id comes from the authenticated context
         * and status from the lifecycle, so neither is mass-assignable. Passing
         * them to create() would have them silently dropped by $fillable, and the
         * insert would fail on the NOT NULL company_id - a confusing error for
         * what is really a missing-scope bug.
         */
        $period = new AccountingPeriod([
            'name' => $data['name'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);

        $period->forceFill([
            'company_id' => $company->getKey(),
            'status' => PeriodStatus::Open->value,
        ]);

        try {
            $period->save();
        } catch (QueryException $e) {
            /*
             * The unique (company_id, name) index is the real guard against two
             * concurrent requests creating the same period. The overlap check
             * above cannot see an uncommitted row from another transaction, so
             * this catch is not redundant with it - it closes the race the
             * application check cannot.
             *
             * Only *that* index is translated. Anything else is re-thrown: a
             * blanket catch would answer "name already in use" for an unrelated
             * failure, sending the user to fix a name that was never the problem.
             */
            if ($this->isDuplicatePeriodName($e)) {
                throw ValidationException::withMessages([
                    'name' => 'This period name is already in use for the selected company.',
                ]);
            }

            throw $e;
        }

        return $period;
    }

    /**
     * Did this query exception come from the (company_id, name) unique index?
     *
     * MySQL reports the constraint by name, and MySQL is the only engine this
     * schema targets - the CHECK constraints added by SchemaCheck require it. So
     * the name is matched directly rather than parsed out of a driver-specific
     * message.
     */
    private function isDuplicatePeriodName(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'accounting_periods_company_id_name_unique')
            || str_contains($message, 'accounting_periods.name');
    }

    /**
     * Update a period's name and dates.
     *
     * A closed period is immutable in Phase 4. Allowing an edit would let a
     * closed period's date range move and silently change which dates are
     * postable, undoing the effect of closing it.
     *
     * @throws ValidationException
     */
    public function update(AccountingPeriod $period, array $data): AccountingPeriod
    {
        if ($period->status->isClosed()) {
            throw ValidationException::withMessages([
                'period' => 'A closed period cannot be modified.',
            ]);
        }

        $start = isset($data['start_date'])
            ? Carbon::parse($data['start_date'])->startOfDay()
            : $period->start_date->startOfDay();

        $end = isset($data['end_date'])
            ? Carbon::parse($data['end_date'])->startOfDay()
            : $period->end_date->startOfDay();

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages([
                'end_date' => 'The period end date must not be before its start date.',
            ]);
        }

        // Exclude self, or a period would always overlap itself.
        $this->assertNoOverlap(
            $period->company,
            $start,
            $end,
            excludingId: $period->getKey()
        );

        try {
            $period->forceFill(array_filter([
                'name' => $data['name'] ?? null,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ], fn ($value) => $value !== null))->save();
        } catch (QueryException $e) {
            if (! $this->isDuplicatePeriodName($e)) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'name' => 'This period name is already in use for the selected company.',
            ]);
        }

        return $period->refresh();
    }

    /**
     * Close a period.
     *
     * Runs in a transaction with a row lock so two concurrent close requests
     * cannot both observe OPEN and both "succeed". The second one blocks, then
     * re-reads a CLOSED row and is rejected.
     *
     * There is intentionally no reopen(). Once closed, only a privileged future
     * mechanism may reopen, and Phase 4 does not create one - see the Phase 4
     * report's Known Limitations.
     *
     * @throws ValidationException
     */
    public function close(AccountingPeriod $period): AccountingPeriod
    {
        return DB::transaction(function () use ($period) {
            $fresh = AccountingPeriod::query()
                ->whereKey($period->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status->isClosed()) {
                throw ValidationException::withMessages([
                    'period' => 'This period is already closed.',
                ]);
            }

            $fresh->forceFill(['status' => PeriodStatus::Closed->value])->save();

            return $fresh;
        });
    }

    /**
     * The period containing a date for this company, if one exists.
     *
     * Null means no period covers the date. That is a valid state for a draft
     * and an error only at posting time, so this method does not throw.
     */
    public function periodForDate(Company $company, Carbon $date): ?AccountingPeriod
    {
        $day = $date->copy()->startOfDay();

        return AccountingPeriod::query()
            ->where('company_id', $company->getKey())
            ->whereDate('start_date', '<=', $day->toDateString())
            ->whereDate('end_date', '>=', $day->toDateString())
            ->first();
    }

    /**
     * Assert a date can accept a posted journal: it must fall inside a period
     * that is currently open.
     *
     * The two failure modes are reported separately on purpose. "No period
     * covers this date" means the user needs to create one; "the period is
     * closed" means the entry is being made too late. Collapsing both into
     * "invalid period" would leave the user guessing which action fixes it.
     *
     * @throws ValidationException
     */
    public function assertPostableDate(Company $company, Carbon $date): AccountingPeriod
    {
        $period = $this->periodForDate($company, $date);

        if ($period === null) {
            throw ValidationException::withMessages([
                'journal_date' => 'No accounting period covers this date. Create the period before posting.',
            ]);
        }

        if ($period->status->isClosed()) {
            throw ValidationException::withMessages([
                'journal_date' => "The accounting period [{$period->name}] is closed and cannot accept new entries.",
            ]);
        }

        return $period;
    }

    /**
     * Reject a range that intersects an existing period of this company.
     *
     * Intersection is inclusive on both ends, so 2027-01-01..2027-01-31 and
     * 2027-02-01..2027-02-28 do not collide, while a range that merely starts
     * inside January does.
     *
     * @throws ValidationException
     */
    private function assertNoOverlap(
        Company $company,
        Carbon $start,
        Carbon $end,
        ?int $excludingId = null
    ): void {
        $query = AccountingPeriod::query()
            ->where('company_id', $company->getKey())
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString());

        if ($excludingId !== null) {
            $query->whereKeyNot($excludingId);
        }

        $clash = $query->first(['name', 'start_date', 'end_date']);

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'start_date' => "These dates overlap the existing period [{$clash->name}] "
                    .'('.Carbon::parse($clash->start_date)->toDateString().' - '
                    .Carbon::parse($clash->end_date)->toDateString().').',
            ]);
        }
    }
}
