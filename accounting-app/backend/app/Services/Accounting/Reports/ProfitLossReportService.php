<?php

namespace App\Services\Accounting\Reports;

use App\Enums\AccountType;
use App\Models\Company;
use App\Services\Accounting\Dimensions\DimensionFilter;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Profit and loss over a period.
 *
 * Revenue and expenses are signed on their own type's normal side, so a revenue
 * account that has been credited more than debited is positive and an expense
 * account that has been debited more than credited is positive. The subtraction
 * is then the ordinary `revenue - expenses`.
 *
 * The window is inclusive on both ends. When no window is given the report
 * covers all posted activity, which is what a client asking "how are we doing"
 * without parameters means.
 *
 * `gross_profit` is the same figure as `net_profit` here. This phase does not
 * model cost of sales separately from other expenses - the chart of accounts has
 * no cost-of-goods type to classify against - so a second total would be a
 * second name for one number. It is included because clients expect the key, and
 * documented as not yet a distinct calculation.
 *
 * DIMENSION-AWARE (Phase 17)
 *
 * When a dimension filter is supplied the report is built from the same posted
 * ledger lines, narrowed to those carrying the label. Nothing is summed or
 * stored for the dimension; the filter is a join against `journal_line_dimensions`
 * inside the one query each section reads. Because it is a derivation over the
 * same rows, the filtered and unfiltered reports reconcile by subtraction, and
 * the `unassigned` block makes that reconciliation explicit: revenue, expenses
 * and net earned on lines NOT carrying the filtered label. When every relevant
 * line carries the label, `unassigned` is all zeros and the dimension P&L is
 * exactly the company P&L (Phase 17 §18, §19).
 */
class ProfitLossReportService extends JournalReportService
{
    /**
     * @return array<string, mixed>
     */
    public function generate(Company $company, ?Carbon $from = null, ?Carbon $to = null, ?DimensionFilter $filter = null): array
    {
        // The dimension view needs the unfiltered totals too, to report what the
        // filter left out. Two reads of the same ledger, never a cached total.
        if ($filter?->isActive()) {
            $filtered = $this->totalsByAccount($company, $from, $to, $filter);
            $unfiltered = $this->totalsByAccount($company, $from, $to);

            return $this->report($company, $from, $to, $filtered, $filter, $unfiltered);
        }

        return $this->report($company, $from, $to, $this->totalsByAccount($company, $from, $to));
    }

    /**
     * The report body, shared by the filtered and unfiltered paths.
     *
     * @param  Collection<int, array{debit: Money, credit: Money}>  $totals
     * @param  Collection<int, array{debit: Money, credit: Money}>|null  $unfiltered
     * @return array<string, mixed>
     */
    private function report(
        Company $company,
        ?Carbon $from,
        ?Carbon $to,
        Collection $totals,
        ?DimensionFilter $filter = null,
        ?Collection $unfiltered = null,
    ): array {
        $revenue = $this->signedRowsForType($company, $totals, AccountType::Revenue);
        $expenses = $this->signedRowsForType($company, $totals, AccountType::Expense);

        $netProfit = $revenue['total']->minus($expenses['total']);

        $data = [
            'period' => $this->period($from, $to),
            'base_currency' => $this->baseCurrency($company),
            'revenue' => [
                'total' => $this->amount($revenue['total']),
                'accounts' => $revenue['rows'],
            ],
            'expenses' => [
                'total' => $this->amount($expenses['total']),
                'accounts' => $expenses['rows'],
            ],
            /*
             * Not a separate calculation in this phase; see the class docblock.
             * Kept as a key so a client does not have to special-case its
             * absence.
             */
            'gross_profit' => $this->amount($netProfit),
            'net_profit' => $this->amount($netProfit),
            'is_profitable' => $netProfit->isPositive(),
        ];

        if ($filter !== null && $unfiltered !== null) {
            $allRevenue = $this->signedRowsForType($company, $unfiltered, AccountType::Revenue);
            $allExpenses = $this->signedRowsForType($company, $unfiltered, AccountType::Expense);

            $unassignedRevenue = $allRevenue['total']->minus($revenue['total']);
            $unassignedExpenses = $allExpenses['total']->minus($expenses['total']);

            $data['dimension_filter'] = $filter->echo();
            $data['unassigned'] = [
                'revenue' => $this->amount($unassignedRevenue),
                'expenses' => $this->amount($unassignedExpenses),
                'net' => $this->amount($unassignedRevenue->minus($unassignedExpenses)),
            ];
        }

        return $data;
    }
}
