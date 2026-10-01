<?php

namespace App\Services\Accounting\Reports;

use App\Enums\AccountType;
use App\Models\Company;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Balance sheet at a point in time.
 *
 * The sheet is cumulative through `to_date`. There is no "as of just this
 * window" balance sheet: a balance sheet always shows everything that has
 * happened up to a moment, so the opening bound is deliberately ignored for the
 * section balances. `from_date` is used for exactly one thing - the
 * `current_period_result` line - which is informational and is *not* added into
 * equity.
 *
 * That last point is the one worth stating plainly. Equity already contains
 * `retained_earnings`, the cumulative net profit through `to_date`. Adding the
 * narrower current-period result on top would double-count the period and break
 * the equation. So:
 *
 *   equity_total  = equity accounts + retained_earnings
 *   assets_total  = liabilities_total + equity_total
 *
 * Section signs use the account TYPE's normal balance, never the per-account
 * contra override. A contra asset is signed on the asset side and therefore
 * reduces the asset total, which is the whole reason it exists; signing by its
 * effective credit balance would inflate the section instead.
 */
class BalanceSheetReportService extends JournalReportService
{
    /**
     * @return array<string, mixed>
     */
    public function generate(Company $company, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $asOf = $to ?? Carbon::today();

        // Cumulative through the as-of date: no lower bound.
        $totals = $this->totalsByAccount($company, null, $asOf);

        $assets = $this->signedRowsForType($company, $totals, AccountType::Asset);
        $liabilities = $this->signedRowsForType($company, $totals, AccountType::Liability);
        $equityAccounts = $this->signedRowsForType($company, $totals, AccountType::Equity);

        $retainedEarnings = $this->retainedEarnings($company, $asOf);
        $equityTotal = $equityAccounts['total']->plus($retainedEarnings);

        $liabilitiesAndEquity = $liabilities['total']->plus($equityTotal);
        $difference = $assets['total']->minus($liabilitiesAndEquity);

        return [
            'period' => ['from' => $from?->toDateString(), 'to' => $asOf->toDateString()],
            'assets' => [
                'total' => $this->amount($assets['total']),
                'accounts' => $assets['rows'],
            ],
            'liabilities' => [
                'total' => $this->amount($liabilities['total']),
                'accounts' => $liabilities['rows'],
            ],
            'equity' => [
                'total' => $this->amount($equityTotal),
                'accounts' => $equityAccounts['rows'],
                // Cumulative net profit through the as-of date, the closing
                // figure temporary accounts roll into.
                'retained_earnings' => $this->amount($retainedEarnings),
            ],
            'totals' => [
                'assets' => $this->amount($assets['total']),
                'liabilities' => $this->amount($liabilities['total']),
                'equity' => $this->amount($equityTotal),
                'liabilities_and_equity' => $this->amount($liabilitiesAndEquity),
                'is_balanced' => $difference->isZero(),
                'difference' => $this->amount($difference->absolute()),
            ],
            /*
             * Informational only. The net result of [from_date, to_date] when a
             * from date is supplied, else the cumulative result. It is reported
             * but never summed into equity - equity already holds the cumulative
             * profit, and adding this would double-count.
             */
            'current_period_result' => $this->amount($this->currentPeriodResult($company, $from, $asOf)),
        ];
    }

    private function currentPeriodResult(Company $company, ?Carbon $from, Carbon $to): Money
    {
        if ($from === null) {
            return $this->retainedEarnings($company, $to);
        }

        $totals = $this->totalsByAccount($company, $from, $to);

        $revenue = $this->signedRowsForType($company, $totals, AccountType::Revenue)['total'];
        $expenses = $this->signedRowsForType($company, $totals, AccountType::Expense)['total'];

        return $revenue->minus($expenses);
    }
}
