<?php

namespace App\Services\Accounting\Reports;

use App\Enums\AccountType;
use App\Models\Company;
use Illuminate\Support\Carbon;

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
 */
class ProfitLossReportService extends JournalReportService
{
    /**
     * @return array<string, mixed>
     */
    public function generate(Company $company, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $totals = $this->totalsByAccount($company, $from, $to);

        $revenue = $this->signedRowsForType($company, $totals, AccountType::Revenue);
        $expenses = $this->signedRowsForType($company, $totals, AccountType::Expense);

        $netProfit = $revenue['total']->minus($expenses['total']);

        return [
            'period' => $this->period($from, $to),
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
    }
}
