<?php

namespace App\Services\Accounting;

use App\Enums\AuditAction;
use App\Enums\PeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creation, validation, closing and reopening of accounting periods.
 *
 * The period model is intentionally thin. There is no lock-date, no cached state
 * and no stored balance - the spec asks for an inclusive date range with an
 * explicit state, and none of that. What is not thin is the enforcement: a date
 * must land in exactly one open period of the company's own, inside a financial
 * year that is itself open, before a journal may post.
 *
 * Phase 8 adds FinancialYearService as the authority on the year that contains a
 * date, and keeps every period rule here, so that posting validation has exactly
 * one implementation rather than one per flow.
 */
class AccountingPeriodService
{
    public function __construct(
        private readonly FinancialYearService $years,
        private readonly AccountingPeriodResolver $resolver,
        private readonly PeriodClosingCheckService $closingCheck,
        private readonly AuditService $audit,
    ) {}

    /**
     * Create a period, rejecting any date range that overlaps an existing one.
     *
     * A period always ends up attached to a financial year. When the caller names
     * one it must be a year of this company that actually contains the period; if
     * the caller names none, the year is derived from the configured fiscal
     * calendar and created if this company has no calendar yet. That derivation is
     * a fact about start_month rather than a business decision, which is why it is
     * allowed to happen here instead of being demanded of the client.
     *
     * @param  array{name: string, start_date: string, end_date: string, financial_year_id?: int|null}  $data
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

        return DB::transaction(function () use ($company, $data, $start, $end) {
            $this->assertNoOverlap($company, $start, $end);

            $year = $this->resolveYearForPeriod($company, $data['financial_year_id'] ?? null, $start);

            $this->assertWithinYear($year, $start, $end);

            /*
             * forceFill, not create(): company_id comes from the authenticated
             * context and status from the lifecycle, so neither is mass-assignable.
             * Passing them to create() would have them silently dropped by
             * $fillable, and the insert would fail on the NOT NULL company_id - a
             * confusing error for what is really a missing-scope bug.
             */
            $period = new AccountingPeriod([
                'name' => $data['name'],
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ]);

            $period->forceFill([
                'company_id' => $company->getKey(),
                'financial_year_id' => $year->getKey(),
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
        });
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
     * A closed period is immutable. Allowing an edit would let a closed period's
     * date range move and silently change which dates are postable, undoing the
     * effect of closing it.
     *
     * @param  array{name?: string, start_date?: string, end_date?: string}  $data
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

        /*
         * A period that already belongs to a year must stay inside it. Moving its
         * dates out of the year would leave a date that no year claims, which is
         * exactly the ambiguity a financial year is meant to remove. A period with
         * no year attached - one of the rows the Phase 8 migration backfilled
         * against a company with no calendar - adopts the derived year instead, so
         * it does not stay orphaned after an edit.
         */
        $year = $period->financialYear;

        if ($year === null) {
            $year = $this->resolveYearForPeriod($period->company, null, $start);
        }

        $this->assertWithinYear($year, $start, $end);

        try {
            $period->forceFill(array_filter([
                'name' => $data['name'] ?? null,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'financial_year_id' => $year->getKey(),
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
     * @param  User  $actor  recorded in closed_by/closed_at. The post is an
     *                       accounting-control act, so who performed it and when
     *                       is part of the record; without it a closed period
     *                       would refuse every posting with no record of who
     *                       decided that.
     *
     * @throws ValidationException
     */
    public function close(AccountingPeriod $period, User $actor): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $actor) {
            $fresh = AccountingPeriod::query()
                ->whereKey($period->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status->isClosed()) {
                throw ValidationException::withMessages([
                    'period' => 'This period is already closed.',
                ]);
            }

            /*
             * Phase 15. Closing is refused while a critical control fails. The
             * review runs after the lock is taken and before anything is written,
             * so the state it inspects is the locked state and a failed review
             * rolls the whole close back. The controls are read-only, so a review
             * can never itself change accounting history.
             */
            $review = $this->closingCheck->review($fresh->company, $fresh);

            if (! $review['eligible']) {
                throw ValidationException::withMessages([
                    'period' => 'This period cannot be closed. '.$review['required_action']
                        .' '.$this->blockingSummary($review['blocking_findings']),
                ]);
            }

            $fresh->forceFill([
                'status' => PeriodStatus::Closed->value,
                'closed_by' => $actor->getKey(),
                'closed_at' => now(),
                /*
                 * Cleared so the two audit pairs cannot both be populated. Each pair
                 * describes the last transition of its own kind; a period that is
                 * currently closed is not currently reopened, and leaving the older
                 * stamp would make a client unable to tell which transition the
                 * close actually reversed.
                 */
                'reopened_by' => null,
                'reopened_at' => null,
            ])->save();

            $this->audit->lifecycle(
                AuditAction::Closed,
                $fresh,
                $actor,
                ['status' => PeriodStatus::Open->value],
                ['status' => PeriodStatus::Closed->value],
                [
                    'period_name' => $fresh->name,
                    'period_start' => $fresh->start_date?->toDateString(),
                    'period_end' => $fresh->end_date?->toDateString(),
                ],
            );

            return $fresh;
        });
    }

    /**
     * Render the blocking findings as one clause for the refusal message.
     *
     * @param  list<array<string, mixed>>  $blocking
     */
    private function blockingSummary(array $blocking): string
    {
        return implode(' ', array_map(
            fn (array $finding) => '['.$finding['control_code'].'] '.$finding['description'],
            array_slice($blocking, 0, 3),
        ));
    }

    /**
     * Reopen a closed period.
     *
     * Phase 4 had no reopen route because it had no permission to gate one with,
     * and its report recorded the consequence: a period closed in error needed a
     * database-level correction. Phase 8 supplies the privileged mechanism that
     * report pointed at. It is still never implicit - a caller must ask for it
     * explicitly, and it carries a permission separate from close, so a role that
     * may close a period does not thereby gain the ability to undo one.
     *
     * Reopening does not touch journal data. History is not rewritten or deleted
     * to make room for new entries; the entries that were posted before the close
     * were legitimate then and remain legitimate now. What changes is only whether
     * *new* postings are accepted for that date range.
     *
     * @throws ValidationException
     */
    public function reopen(AccountingPeriod $period, User $actor): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $actor) {
            $fresh = AccountingPeriod::query()
                ->whereKey($period->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status->isOpen()) {
                throw ValidationException::withMessages([
                    'period' => 'This period is already open.',
                ]);
            }

            /*
             * A period inside a closed financial year cannot be reopened on its
             * own. The year is the outer statement that the span is finished, and
             * reopening one month of it would leave the year claiming a period is
             * live that it declared closed. The year must be reopened first, and
             * Phase 8 does not implement financial-year reopening - so this is a
             * dead end by design rather than an oversight, and saying so is more
             * useful than reopening the period and leaving the two disagreeing.
             */
            $year = $fresh->financialYear;

            if ($year !== null && $year->status->isClosed()) {
                throw ValidationException::withMessages([
                    'period' => 'This period belongs to a closed financial year ['.$year->name.']. '
                        .'Reopen the financial year before reopening one of its periods.',
                ]);
            }

            $fresh->forceFill([
                'status' => PeriodStatus::Open->value,
                /*
                 * Cleared rather than left in place: closed_by answers "who closed
                 * this", and an open period is not closed by anyone. Keeping the old
                 * attribution would report a closer for a period that accepts
                 * postings, and would leave one column describing two different
                 * states.
                 */
                'closed_by' => null,
                'closed_at' => null,
                /*
                 * The reopener is recorded, which is the point of §22: undoing an
                 * accounting-control decision is the more consequential act of the
                 * two, so it is the one that must leave an attributable trace. A
                 * later close clears this pair again, so each pair describes the
                 * last transition rather than accumulating a history of them.
                 */
                'reopened_by' => $actor->getKey(),
                'reopened_at' => now(),
            ])->save();

            $this->audit->lifecycle(
                AuditAction::Reopened,
                $fresh,
                $actor,
                ['status' => PeriodStatus::Closed->value],
                ['status' => PeriodStatus::Open->value],
                [
                    'period_name' => $fresh->name,
                    'period_start' => $fresh->start_date?->toDateString(),
                    'period_end' => $fresh->end_date?->toDateString(),
                ],
            );

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
        return $this->resolver->findPeriod($company, $date);
    }

    /**
     * Resolve the accounting period that owns an accounting date.
     *
     * The single definition of "which period owns this date". Callers should not
     * reproduce the lookup: the rule is company-scoped, inclusive at both ends,
     * and must also agree with the financial year, which is not something an
     * ad-hoc query would check.
     *
     * @return array{period: AccountingPeriod, financial_year: FinancialYear|null}
     *
     * @throws ValidationException
     */
    public function resolveForDate(Company $company, Carbon $date): array
    {
        $period = $this->periodForDate($company, $date);

        if ($period === null) {
            throw ValidationException::withMessages([
                'journal_date' => 'No accounting period covers this date. Create the period before posting.',
            ]);
        }

        return [
            'period' => $period,
            /*
             * Normally the period's own year. A period created before Phase 8 may
             * have no year attached, so the containing year is resolved rather than
             * assumed - it is the same date lookup either way, and reading it this
             * way keeps a legacy row from silently skipping the year check.
             */
            'financial_year' => $period->financialYear
                ?? $this->resolver->findFinancialYear($company, $date),
        ];
    }

    /**
     * Assert a date can accept a posted journal: it must fall inside a period
     * that is currently open, and inside a financial year that is also open.
     *
     * The failure modes are reported separately on purpose. "No period covers
     * this date" means the user needs to create one; "the period is closed" means
     * the entry is being made too late; "the financial year is closed" means the
     * whole span is finished. Collapsing them into "invalid period" would leave
     * the user guessing which action fixes it.
     *
     * $field names the request field the failure is reported against, so each flow
     * blames its own date rather than `journal_date`. Every flow reaches this method
     * through a generated journal, but the user is editing an invoice or a bill, and
     * an error attached to a field that does not exist on their form is an error
     * they cannot act on. Defaults to journal_date for a manual journal.
     *
     * The period is re-read under a row lock, inside the caller's transaction.
     * That is what makes closing race-safe against posting: a concurrent close
     * takes the same lock, so the two are serialised and a posting cannot observe
     * "open", then commit after a close has already committed. An unlocked
     * check-then-act would allow exactly that interleaving.
     *
     * @throws ValidationException
     */
    public function assertPostableDate(
        Company $company,
        Carbon $date,
        string $field = 'journal_date'
    ): AccountingPeriod {
        $day = $date->copy()->startOfDay();

        $period = AccountingPeriod::query()
            ->where('company_id', $company->getKey())
            ->whereDate('start_date', '<=', $day->toDateString())
            ->whereDate('end_date', '>=', $day->toDateString())
            ->lockForUpdate()
            ->first();

        if ($period === null) {
            throw ValidationException::withMessages([
                $field => 'No accounting period covers this date. Create the period before posting.',
            ]);
        }

        if ($period->status->isClosed()) {
            throw ValidationException::withMessages([
                $field => "The accounting period [{$period->name}] is closed and cannot accept new entries.",
            ]);
        }

        $year = $period->financialYear ?? $this->resolver->findFinancialYear($company, $date);

        if ($year !== null && $year->status->isClosed()) {
            throw ValidationException::withMessages([
                $field => "The financial year [{$year->name}] is closed and cannot accept new entries.",
            ]);
        }

        return $period;
    }

    /**
     * Assert a date is not inside a closed period.
     *
     * The counterpart to assertPostableDate(), for operations that are not
     * postings: editing or deleting a draft document whose accounting date falls
     * in a closed period.
     *
     * A date with no period at all is accepted here. This method answers "is this
     * date closed?", and an uncovered date is not closed; refusing it would block
     * the ordinary correction of a draft dated before the calendar was set up.
     * Posting is where an uncovered date is rejected, with a message telling the
     * user to create the period.
     *
     * Note what is deliberately NOT called here: assertPostableDate(). A draft is
     * not yet accounting data, so requiring it to sit in an *open* period at
     * edit time would make a draft undeletable the moment its month closed, which
     * is a different and much harsher rule than the accounting one.
     *
     * @throws ValidationException
     */
    public function assertDateNotClosed(Company $company, Carbon $date, string $field = 'date'): void
    {
        $period = $this->periodForDate($company, $date);

        if ($period === null || $period->status->isOpen()) {
            return;
        }

        throw ValidationException::withMessages([
            $field => "The accounting period [{$period->name}] is closed. "
                .'This record cannot be modified because its accounting date falls in that period.',
        ]);
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

    /**
     * The financial year a period belongs to: the one the caller named, or the
     * one derived from the configured fiscal calendar.
     *
     * A named year is validated rather than trusted, because it arrives as a
     * foreign key from a request body and naming another company's year would
     * otherwise attach this company's period to it.
     *
     * @throws ValidationException
     */
    private function resolveYearForPeriod(Company $company, ?int $yearId, Carbon $start): FinancialYear
    {
        if ($yearId !== null) {
            $year = FinancialYear::query()
                ->where('company_id', $company->getKey())
                ->whereKey($yearId)
                ->first();

            if ($year === null) {
                throw ValidationException::withMessages([
                    'financial_year_id' => 'The selected financial year does not belong to this company.',
                ]);
            }

            return $year;
        }

        return $this->years->resolveOrCreateForDate($company, $start);
    }

    /**
     * Reject a period that would sit outside the financial year it belongs to.
     *
     * Without this, a date could be claimed by a period whose year does not
     * contain it, and the resolver would then have two candidate years for the
     * same day - one from the period and one from the date - which is the
     * ambiguity the year layer exists to remove.
     *
     * @throws ValidationException
     */
    private function assertWithinYear(FinancialYear $year, Carbon $start, Carbon $end): void
    {
        $yearStart = $year->start_date->startOfDay();
        $yearEnd = $year->end_date->startOfDay();

        if ($start->lessThan($yearStart) || $end->greaterThan($yearEnd)) {
            throw ValidationException::withMessages([
                'financial_year_id' => "These dates fall outside financial year [{$year->name}] "
                    .'('.$yearStart->toDateString().' - '.$yearEnd->toDateString().').',
            ]);
        }
    }
}
