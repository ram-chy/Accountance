<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FinancialYear;
use Illuminate\Support\Carbon;

/**
 * Resolution of the accounting period that owns an accounting date.
 *
 * Split out from AccountingPeriodService deliberately, and the split is by
 * responsibility rather than by convenience:
 *
 *   AccountingPeriodService  - lifecycle. Creating, renaming, closing, reopening
 *                              a period, and the rules that govern those acts.
 *   AccountingPeriodResolver - lookup. Which period, and which financial year,
 *                              does this date belong to.
 *
 * A posting flow needs the second and not the first. Inlining the resolution into
 * the lifecycle service would drag a period-mutating service into every posting
 * path, and a resolver that could also close periods is a resolver someone will
 * eventually call to close one.
 *
 * Deliberately not a static class: it is resolved through the container so a
 * posting service can take it as a constructor dependency like every other
 * service here, and so tests can substitute it if a future flow needs to.
 */
class AccountingPeriodResolver
{
    /**
     * The period containing a date for this company, or null.
     *
     * Null is a real answer, not a failure: a date before the calendar was set up
     * belongs to no period. Callers decide whether that is acceptable (a draft may
     * be created) or an error (a posting may not).
     *
     * The comparison is inclusive at both ends and done on the date alone, so a
     * period running 2027-01-01..2027-01-31 claims 2027-01-01 and 2027-01-31.
     */
    public function findPeriod(Company|int $company, Carbon $date): ?AccountingPeriod
    {
        $companyId = $company instanceof Company ? $company->getKey() : $company;

        $day = $date->copy()->startOfDay();

        return AccountingPeriod::query()
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $day->toDateString())
            ->whereDate('end_date', '>=', $day->toDateString())
            ->first();
    }

    /**
     * The financial year containing a date for this company, or null.
     *
     * Read-only: unlike FinancialYearService::resolveOrCreateForDate() this never
     * writes. A posting path asking "which year is this" must not create one as a
     * side effect of answering, and the two methods exist separately so that the
     * mutating one can only be reached deliberately.
     */
    public function findFinancialYear(Company|int $company, Carbon $date): ?FinancialYear
    {
        $companyId = $company instanceof Company ? $company->getKey() : $company;

        $day = $date->copy()->startOfDay();

        return FinancialYear::query()
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $day->toDateString())
            ->whereDate('end_date', '>=', $day->toDateString())
            ->first();
    }

    /**
     * The period and financial year that own a date.
     *
     * Both are returned even though callers usually need only one, because the two
     * can disagree: a period that sits outside the year it is attached to (which
     * the services prevent, but a hand-edited row could still do) must be visible
     * to whoever is asking rather than silently resolved in the period's favour.
     *
     * @return array{period: ?AccountingPeriod, financial_year: ?FinancialYear}
     */
    public function resolve(Company|int $company, Carbon $date): array
    {
        return [
            'period' => $this->findPeriod($company, $date),
            'financial_year' => $this->findFinancialYear($company, $date),
        ];
    }

    /**
     * Does a date sit inside a closed period?
     *
     * The question every draft-lifecycle guard asks. Answered from the period's
     * status rather than by comparing dates to "now", because what matters is
     * whether an accountant has declared the month finished, not whether time has
     * passed.
     */
    public function isClosed(Company|int $company, Carbon $date): bool
    {
        $period = $this->findPeriod($company, $date);

        return $period !== null && $period->status->isClosed();
    }

    /**
     * Can a date accept a new posting?
     *
     * True only when a period exists, it is open, and the financial year
     * containing it is open. A date with no period is not postable, which is the
     * opposite of isClosed(): uncovered is "not set up yet", closed is "finished".
     */
    public function acceptsPostings(Company|int $company, Carbon $date): bool
    {
        $period = $this->findPeriod($company, $date);

        if ($period === null || $period->status->isClosed()) {
            return false;
        }

        $year = $period->financialYear ?? $this->findFinancialYear($company, $date);

        return $year === null || $year->isOpen();
    }
}
