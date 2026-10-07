<?php

namespace App\Services\Accounting;

use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\Company;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives account balances and trial balances from posted journal lines.
 *
 * There is deliberately no `ledger_balances` table. The spec rules out a
 * duplicated source of truth, and a cached-balance table is exactly that: it
 * can disagree with the lines it summarises, and every disagreement has to be
 * reconciled by some future maintenance job. Posting already recomputes nothing
 * because posting writes lines, not balances, so the "cache" would only ever be
 * a performance shortcut - and Phase 4 is told not to optimise prematurely.
 *
 * Balances are therefore always computed from `journal_lines JOIN journals WHERE
 * journals.status = 'POSTED'`. That is also what makes drafts invisible to
 * financial reports for free: they are simply not in the result set.
 */
class LedgerService
{
    public function __construct(private readonly AccountingRules $rules) {}

    /**
     * Raw debit and credit totals for one account, from posted lines only.
     *
     * @return array{debit: Money, credit: Money}
     */
    public function totalsFor(Account $account, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $row = $this->postedLinesQuery($account->company_id)
            ->where('journal_lines.account_id', $account->getKey())
            ->when($from, fn (Builder $q) => $q->whereDate('journals.journal_date', '>=', $from->toDateString()))
            ->when($to, fn (Builder $q) => $q->whereDate('journals.journal_date', '<=', $to->toDateString()))
            ->selectRaw('coalesce(sum(journal_lines.debit), 0) as total_debit, '
                .'coalesce(sum(journal_lines.credit), 0) as total_credit')
            ->first();

        /*
         * MySQL's SUM over DECIMAL returns DECIMAL, so the strings handed back
         * here are exact. They are still routed through Money rather than used
         * directly: the cast to string is not a guarantee about scale or
         * formatting, and every downstream arithmetic step assumes Money's
         * canonical form.
         */
        return [
            'debit' => Money::of((string) $row->total_debit),
            'credit' => Money::of((string) $row->total_credit),
        ];
    }

    /**
     * An account's signed balance, respecting its normal balance.
     *
     * A positive result is a balance on the account's normal side. A negative
     * result means it is over-balanced the other way - an asset with more
     * credits than debits, which is a real state worth reporting honestly
     * rather than a value to hide by taking absoluteValue.
     *
     * Inactive accounts are included: they keep their history and must continue
     * to appear in reports.
     */
    public function balanceFor(Account $account, ?Carbon $from = null, ?Carbon $to = null): Money
    {
        ['debit' => $debit, 'credit' => $credit] = $this->totalsFor($account, $from, $to);

        return $this->rules->signedBalance($debit, $credit, $this->rules->normalBalanceFor($account));
    }

    /**
     * The trial balance for a company at a point in time.
     *
     * Each account gets its signed balance, which is then placed in the debit or
     * credit column according to its normal balance. Accounts with no movement
     * are omitted rather than shown as zero rows: a trial balance lists what has
     * been booked, not every account the chart defines.
     *
     * @param  Carbon|null  $to  inclusive upper bound on journal_date
     * @return array{accounts: Collection<int, array<string, mixed>>, total_debit: Money, total_credit: Money, balanced: bool}
     */
    public function trialBalance(Company $company, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $totals = $this->postedTotalsByAccount($company, $from, $to);

        // Accounts are keyed by id, so an account with no posted lines is simply
        // absent from the map and drops out of the trial balance.
        $accounts = Account::query()
            ->where('company_id', $company->getKey())
            ->whereIn('id', $totals->keys())
            ->orderBy('code')
            ->get();

        $rows = [];
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();

        foreach ($accounts as $account) {
            $debit = $totals->get($account->getKey())['debit'];
            $credit = $totals->get($account->getKey())['credit'];

            $normalBalance = $this->rules->normalBalanceFor($account);
            $signed = $this->rules->signedBalance($debit, $credit, $normalBalance);

            if ($signed->isZero()) {
                continue;
            }

            $split = $this->rules->splitForTrialBalance($signed, $normalBalance);

            $totalDebit = $totalDebit->plus($split['debit']);
            $totalCredit = $totalCredit->plus($split['credit']);

            $rows[] = [
                'account_id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'normal_balance' => $normalBalance->value,
                'is_active' => $account->is_active,
                'debit' => (string) $split['debit'],
                'credit' => (string) $split['credit'],
                'balance' => (string) $signed,
            ];
        }

        return [
            'accounts' => collect($rows),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            /*
             * The trial-balance footing check. Because every account's balance
             * is split across two columns, this must hold exactly; a mismatch
             * would mean posted lines do not sum to zero, i.e. the ledger is
             * corrupt. It is returned rather than thrown so a caller can display
             * it, but nothing should ever report a false one.
             */
            'balanced' => $totalDebit->equals($totalCredit),
        ];
    }

    /**
     * Aggregate debit/credit per account across one company's posted lines.
     *
     * Filtering on journals.company_id here is the tenant boundary for every
     * report in the application. It is not merely a filter on the accounts
     * query: the lines are joined through journals precisely so the company
     * check cannot be forgotten on the line side.
     *
     * Public because the Phase 6 report services need exactly this aggregate.
     * Re-implementing it per report would be a second definition of which lines
     * count, and the two would eventually disagree - the failure mode the
     * phase 4 report specifically designed this class to prevent.
     *
     * @return Collection<int, array{debit: Money, credit: Money}>
     */
    public function postedTotalsByAccount(Company $company, ?Carbon $from, ?Carbon $to): Collection
    {
        return $this->postedLinesQuery($company->getKey())
            ->when($from, fn (Builder $q) => $q->whereDate('journals.journal_date', '>=', $from->toDateString()))
            ->when($to, fn (Builder $q) => $q->whereDate('journals.journal_date', '<=', $to->toDateString()))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id as account_id, '
                .'coalesce(sum(journal_lines.debit), 0) as total_debit, '
                .'coalesce(sum(journal_lines.credit), 0) as total_credit')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->account_id => [
                    'debit' => Money::of((string) $row->total_debit),
                    'credit' => Money::of((string) $row->total_credit),
                ],
            ]);
    }

    /**
     * The one query shape every balance calculation shares.
     *
     * `status = 'POSTED'` is what excludes drafts from the ledger, and it is
     * here rather than at each call site so no report can forget it. The
     * company filter is on the joined journals table because journal_lines has
     * no company_id of its own by design.
     *
     * Public for the same reason as postedTotalsByAccount(): the Phase 6
     * general-ledger and cash/bank reports need the identical posted-only,
     * company-scoped base query, and the rule that drafts are invisible must
     * live in one place.
     */
    public function postedLinesQuery(int $companyId): Builder
    {
        return DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.company_id', $companyId)
            ->where('journals.status', JournalStatus::Posted->value);
    }

    /**
     * A per-account movement listing, newest first - the shape an account
     * statement needs. Included because §54 requires verifying a running
     * balance across several transactions, and a running-balance report is the
     * honest way to prove that.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function accountStatement(Account $account, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $normalBalance = $this->rules->normalBalanceFor($account);

        $rows = $this->postedLinesQuery($account->company_id)
            ->where('journal_lines.account_id', $account->getKey())
            ->when($from, fn (Builder $q) => $q->whereDate('journals.journal_date', '>=', $from->toDateString()))
            ->when($to, fn (Builder $q) => $q->whereDate('journals.journal_date', '<=', $to->toDateString()))
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->orderBy('journals.journal_date')
            ->orderBy('journals.journal_number')
            ->orderBy('journal_lines.line_number')
            ->select([
                'journals.journal_number',
                'journals.journal_date',
                'journal_lines.description',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.currency_id',
                'journal_lines.foreign_debit',
                'journal_lines.foreign_credit',
                'journal_lines.exchange_rate',
            ])
            ->get();

        $runningDebit = Money::zero();
        $runningCredit = Money::zero();

        return $rows->map(function ($row) use (&$runningDebit, &$runningCredit, $normalBalance) {
            $debit = Money::of((string) $row->debit);
            $credit = Money::of((string) $row->credit);

            /*
             * The running balance is recomputed from cumulative debit and credit
             * totals rather than by adding a delta to the previous signed value.
             * Adding the delta would need the sign of the running balance to
             * decide which column to subtract from, which silently breaks when a
             * balance crosses zero - and flipping the sign at zero is exactly the
             * kind of edge case a ledger must not get wrong.
             */
            $runningDebit = $runningDebit->plus($debit);
            $runningCredit = $runningCredit->plus($credit);

            $running = $this->rules->signedBalance($runningDebit, $runningCredit, $normalBalance);

            /*
             * §22: the base debit/credit above remain authoritative; these four
             * are explanatory provenance for a foreign line and null on a
             * base-currency one. Returning them on every row keeps the shape
             * stable so a client never has to guess whether the key exists.
             */
            $isForeign = $row->currency_id !== null;

            return [
                'journal_number' => $row->journal_number,
                'journal_date' => Carbon::parse($row->journal_date)->toDateString(),
                'description' => $row->description,
                'debit' => (string) $debit,
                'credit' => (string) $credit,
                'running_balance' => (string) $running,
                'currency_id' => $isForeign ? (int) $row->currency_id : null,
                'exchange_rate' => $row->exchange_rate,
                'foreign_debit' => $isForeign && $row->foreign_debit !== null
                    ? Money::of((string) $row->foreign_debit)->toDatabase()
                    : null,
                'foreign_credit' => $isForeign && $row->foreign_credit !== null
                    ? Money::of((string) $row->foreign_credit)->toDatabase()
                    : null,
            ];
        });
    }
}
