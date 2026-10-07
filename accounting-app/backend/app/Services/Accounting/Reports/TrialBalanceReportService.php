<?php

namespace App\Services\Accounting\Reports;

use App\Models\Account;
use App\Models\Company;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Trial balance: gross debit and credit totals per account, from posted lines.
 *
 * The shape specified for Phase 6 differs from Phase 4's `/accounting/trial-balance`
 * in one meaningful way. Phase 4 puts each account's *net* balance into a debit
 * or credit column, so the two columns foot but a busy account with equal debits
 * and credits is invisible. Phase 6 reports the gross debit and gross credit
 * that actually moved, plus the net as a third figure, which is what an
 * accountant wants when reconciling activity rather than just footing the
 * ledger. Both read the same posted lines through LedgerService; neither stores
 * anything.
 */
class TrialBalanceReportService extends JournalReportService
{
    /**
     * @return array{period: array{from: string|null, to: string|null}, rows: array<int, array<string, mixed>>, totals: array<string, mixed>, include_zero_balances: bool}
     */
    public function generate(
        Company $company,
        ?Carbon $from = null,
        ?Carbon $to = null,
        bool $includeZeroBalances = false
    ): array {
        $totals = $this->totalsByAccount($company, $from, $to);

        $accounts = Account::query()
            ->where('company_id', $company->getKey())
            ->whereIn('id', $totals->keys())
            ->orderBy('code')
            ->get();

        $rows = [];
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();

        foreach ($accounts as $account) {
            $bucket = $totals->get($account->getKey());
            $debit = $bucket['debit'];
            $credit = $bucket['credit'];
            $net = $this->netDebit($debit, $credit);

            /*
             * A zero-balance account here means one whose debits and credits
             * cancel. It is omitted by default because a trial balance lists
             * what has a balance, not every account that was touched. The gross
             * columns are unaffected by the omission: a cancelled account
             * contributes the same amount to both sides, so removing it keeps
             * the totals footed.
             */
            if (! $includeZeroBalances && $net->isZero()) {
                continue;
            }

            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);

            $rows[] = [
                'account_id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'debit_total' => $this->amount($debit),
                'credit_total' => $this->amount($credit),
                'net_balance' => $this->amount($net),
            ];
        }

        $difference = $totalDebit->minus($totalCredit);

        return [
            'period' => $this->period($from, $to),
            /*
             * §21.1 / §23: every figure here is base-currency only. Foreign
             * transaction metadata does not create a second trial balance - the
             * debit and credit totals come from `journal_lines.debit/credit`,
             * which are always the company's functional currency.
             */
            'base_currency' => $this->baseCurrency($company),
            'rows' => $rows,
            'totals' => [
                'debit_total' => $this->amount($totalDebit),
                'credit_total' => $this->amount($totalCredit),
                'net_balance' => $this->amount($totalDebit->minus($totalCredit)),
                /*
                 * The footing check. Posted journals balance by construction, so
                 * a false here means the stored lines are corrupt; it is returned
                 * rather than thrown so the caller can show it, but no valid
                 * ledger ever reports false.
                 */
                'is_balanced' => $totalDebit->equals($totalCredit),
                'difference' => $this->amount($difference->absolute()),
            ],
            'include_zero_balances' => $includeZeroBalances,
        ];
    }
}
