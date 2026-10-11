<?php

namespace App\Services\Accounting\Budgets;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Company;
use App\Services\Accounting\Dimensions\DimensionFilter;
use App\Services\Accounting\Reports\JournalReportService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Budget versus actual.
 *
 * THE ACTUAL FIGURES ARE THE LEDGER. Nothing here reads a stored actual, because
 * there is no stored actual: the actual for a line is the posted movement for
 * that account in that accounting period, taken from the same
 * LedgerService::postedTotalsByAccount() that the profit-and-loss report uses. A
 * budget report and the P&L for the same window therefore cannot disagree about
 * what was actually earned or spent - they are the same numbers.
 *
 * SIGNING
 *
 * Both sides of the comparison are expressed on the account type's normal side.
 * A budget line stores its amount as a positive magnitude on that side (see
 * BudgetLine), and the actual is signed here with signedForType(). Revenue is
 * credit-normal, so "actual 120000, budget 100000" is a variance of +20000;
 * expense is debit-normal, so "actual 80000, budget 100000" is a variance of
 * -20000.
 *
 * FAVOURABILITY IS NOT THE SIGN OF THE VARIANCE
 *
 * A positive variance means actual exceeded plan. For revenue that is good; for
 * expense it is bad. The direction is read from the account type - the same
 * Authority that decides every other sign in the system - rather than being
 * hard-coded here, so a contra account behaves consistently with the P&L.
 *
 * The variance is computed per (account, period). Totals are only aggregated
 * within a section (revenue, expenses, net), because adding a revenue plan to an
 * expense plan produces a number that means nothing.
 */
class BudgetVarianceReportService extends JournalReportService
{
    /**
     * @return array<string, mixed>
     */
    public function generate(Budget $budget, Company $company, ?DimensionFilter $filter = null): array
    {
        $budget->loadMissing([
            'financialYear',
            'lines.account',
            'lines.accountingPeriod',
            'lines.budgetLineDimensions',
        ]);

        /*
         * Phase 17: when a dimension is asked for, both sides of the comparison
         * are scoped by the same label - the plan lines that carry it, against
         * the posted ledger lines that carry it. A line without the label drops
         * out of the view; it still exists in the approved plan, it is just not
         * part of this dimension's variance.
         */
        $lines = $budget->lines
            ->filter(fn (BudgetLine $line) => $filter?->matches($line->budgetLineDimensions->all()) ?? true);

        return $this->build(
            $budget,
            $company,
            $lines,
            $this->totalsByPeriod($company, $lines, $filter),
            $filter,
        );
    }

    /**
     * Build the report, keeping the sign and aggregation logic linear and
     * readable.
     *
     * @param  Collection<int, BudgetLine>  $lines
     * @param  array<int, Collection<int, array{debit: Money, credit: Money}>>  $totalsByPeriod
     * @return array<string, mixed>
     */
    private function build(Budget $budget, Company $company, Collection $lines, array $totalsByPeriod, ?DimensionFilter $filter = null): array
    {
        $rows = [];
        $revenueBudget = Money::zero();
        $revenueActual = Money::zero();
        $expenseBudget = Money::zero();
        $expenseActual = Money::zero();
        $favourable = 0;
        $unfavourable = 0;
        $onTarget = 0;

        foreach ($lines as $line) {
            $account = $line->account;
            $period = $line->accountingPeriod;

            $budgeted = Money::of($line->getRawOriginal('amount'));
            $actual = $this->actualFor($totalsByPeriod, $line);
            $variance = $actual->minus($budgeted);
            $status = $this->statusFor($account->account_type, $variance);

            if ($status === 'FAVOURABLE') {
                $favourable++;
            } elseif ($status === 'UNFAVOURABLE') {
                $unfavourable++;
            } else {
                $onTarget++;
            }

            if ($account->account_type === AccountType::Revenue) {
                $revenueBudget = $revenueBudget->plus($budgeted);
                $revenueActual = $revenueActual->plus($actual);
            } else {
                $expenseBudget = $expenseBudget->plus($budgeted);
                $expenseActual = $expenseActual->plus($actual);
            }

            $rows[] = [
                'account_id' => $account->getKey(),
                'account_code' => $account->code,
                'account_name' => $account->name,
                'account_type' => $account->account_type->value,
                'accounting_period_id' => $period->getKey(),
                'period_name' => $period->name,
                'period_start' => $period->start_date->toDateString(),
                'period_end' => $period->end_date->toDateString(),
                'budget' => $this->amount($budgeted),
                'actual' => $this->amount($actual),
                'variance' => $this->amount($variance),
                'variance_percentage' => $this->percentage($variance, $budgeted),
                'status' => $status,
            ];
        }

        $revenueVariance = $revenueActual->minus($revenueBudget);
        $expenseVariance = $expenseActual->minus($expenseBudget);
        $netBudget = $revenueBudget->minus($expenseBudget);
        $netActual = $revenueActual->minus($expenseActual);
        $netVariance = $netActual->minus($netBudget);

        $year = $budget->financialYear;

        $report = [
            'budget' => [
                'id' => $budget->getKey(),
                'code' => $budget->code,
                'name' => $budget->name,
                'version_number' => $budget->version_number,
                'status' => $budget->status->value,
                'financial_year' => [
                    'id' => $year?->getKey(),
                    'name' => $year?->name,
                    'start_date' => $year?->start_date?->toDateString(),
                    'end_date' => $year?->end_date?->toDateString(),
                ],
            ],
            'period' => [
                'from' => $year?->start_date?->toDateString(),
                'to' => $year?->end_date?->toDateString(),
            ],
            'base_currency' => $this->baseCurrency($company),
            'summary' => [
                'revenue' => [
                    'budget' => $this->amount($revenueBudget),
                    'actual' => $this->amount($revenueActual),
                    'variance' => $this->amount($revenueVariance),
                ],
                'expenses' => [
                    'budget' => $this->amount($expenseBudget),
                    'actual' => $this->amount($expenseActual),
                    'variance' => $this->amount($expenseVariance),
                ],
                'net' => [
                    'budget' => $this->amount($netBudget),
                    'actual' => $this->amount($netActual),
                    'variance' => $this->amount($netVariance),
                    'status' => $this->statusFor(AccountType::Revenue, $netVariance),
                ],
                'counts' => [
                    'favourable' => $favourable,
                    'unfavourable' => $unfavourable,
                    'on_target' => $onTarget,
                ],
            ],
            'lines' => $rows,
        ];

        if ($filter?->isActive()) {
            $report['dimension_filter'] = $filter->echo();
        }

        return $report;
    }

    /**
     * Posted totals per accounting period referenced by the budget, fetched once
     * per period rather than once per line.
     *
     * @param  Collection<int, BudgetLine>  $lines
     * @return array<int, Collection<int, array{debit: Money, credit: Money}>>
     */
    private function totalsByPeriod(Company $company, Collection $lines, ?DimensionFilter $filter = null): array
    {
        $totals = [];

        foreach ($lines->groupBy('accounting_period_id') as $periodId => $group) {
            $period = $group->first()->accountingPeriod;

            $totals[(int) $periodId] = $this->totalsByAccount(
                $company,
                Carbon::instance($period->start_date)->startOfDay(),
                Carbon::instance($period->end_date)->startOfDay(),
                $filter,
            );
        }

        return $totals;
    }

    /**
     * The posted actual for one line's account in its period, on the account
     * type's normal side. Zero when nothing posted.
     *
     * @param  array<int, Collection<int, array{debit: Money, credit: Money}>>  $totalsByPeriod
     */
    private function actualFor(array $totalsByPeriod, BudgetLine $line): Money
    {
        $bucket = $totalsByPeriod[$line->accounting_period_id] ?? null;

        if ($bucket === null) {
            return Money::zero();
        }

        $totals = $bucket->get($line->account_id);

        if ($totals === null) {
            return Money::zero();
        }

        return $this->signedForType($totals['debit'], $totals['credit'], $line->account->account_type);
    }

    /**
     * Exceeded plan in the direction the account type considers good.
     */
    private function statusFor(AccountType $type, Money $variance): string
    {
        if ($variance->isZero()) {
            return 'ON_TARGET';
        }

        $favourable = $type->increasesOnCredit()
            ? $variance->isPositive()
            : $variance->isNegative();

        return $favourable ? 'FAVOURABLE' : 'UNFAVOURABLE';
    }

    /**
     * The variance as a percentage of the plan, or null when the plan is zero.
     *
     * A percentage of zero is undefined, not infinite and not zero. Returning null
     * is the only honest answer, and it is what lets a client render "n/a" instead
     * of a fabricated figure - which matters because a zero-budget line with any
     * actual is exactly the line a reviewer needs to notice.
     */
    private function percentage(Money $variance, Money $budgeted): ?string
    {
        if ($budgeted->isZero()) {
            return null;
        }

        return $variance->dividedBy($budgeted)->timesInt(100)->toDatabase();
    }
}
