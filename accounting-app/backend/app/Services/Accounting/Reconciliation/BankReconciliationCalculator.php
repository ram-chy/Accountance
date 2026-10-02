<?php

namespace App\Services\Accounting\Reconciliation;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\User;
use App\Services\Accounting\LedgerService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The reconciliation arithmetic, in one place.
 *
 * The brief is emphatic that Phase 9 must not become a second balance engine,
 * and the way this project has enforced that throughout is to keep exactly one
 * definition of each fact and refuse to restate it. So this class holds the
 * three questions Phase 9 asks that the ledger did not already answer, and
 * answers nothing else:
 *
 *   1. What is the ledger position through the statement period?
 *      -> LedgerService, reused. Not restated here. See below.
 *   2. Which posted movements are cleared, and which are not?
 *      -> this class.
 *   3. Does the statement agree with the ledger?
 *      -> this class, from (1) and (2).
 *
 * Question 1 is why LedgerService is not called directly from the summary: a
 * reconciliation reports an opening balance, period debits, period credits and a
 * closing balance, and getting those from three separate ledger calls would mean
 * three separate chances to state the balance rule slightly differently. They
 * come from one LedgerService call here instead, so `ledger_closing` is by
 * construction the same number the account balance endpoint and the Phase 6 cash
 * and bank report produce.
 *
 * THE EQUATION
 *
 * A bank account is an asset, so its ledger balance is debit-positive:
 *
 *   ledger_balance = cumulative_debit - cumulative_credit
 *
 * A statement is what the bank says it holds. The two agree only once every
 * difference between them has been accounted for, and the only differences that
 * exist at statement time are movements the ledger has recorded and the bank has
 * not yet honoured - or the reverse. An uncleared debit is money the ledger says
 * arrived and the bank has not seen yet, so it is in the ledger and not on the
 * statement. An uncleared credit is money the ledger says left and the bank has
 * not yet deducted, so it is off the ledger and still on the statement. Hence:
 *
 *   reconciled_balance = ledger_closing
 *                      - uncleared_debits
 *                      + uncleared_credits
 *
 *   difference        = statement_closing - reconciled_balance
 *
 * Every operation is a Money operation. `difference` is compared to zero with
 * Money::isZero(), which is bcmath at a fixed scale; a `=== 0` on a float would
 * report a 125.0000 difference as reconciled whenever the two happened to land
 * on the same binary approximation, which is precisely the bug this project
 * introduced Money to prevent.
 *
 * Nothing here writes. No method in this class creates a journal, changes a
 * journal line, or adjusts a balance to make a difference disappear. A
 * reconciliation that cannot be proved balanced is reported as unbalanced, and
 * the honest answer to "why is there a difference" is that something in the
 * ledger or the statement is wrong and a person has to look - not that the
 * system can book its way out.
 */
class BankReconciliationCalculator
{
    public function __construct(
        private readonly BankReconciliationMovementService $movements,
    ) {}

    /**
     * The opening ledger balance: everything posted to the account before the
     * statement period began.
     *
     * `to` is the day before from_date rather than the period's own start,
     * because the ledger's date filters are inclusive and the opening balance is
     * by definition everything strictly before the first day of the period. A
     * movement dated exactly on from_date belongs to the period, and therefore to
     * the period's debits and credits rather than to what came before it.
     */
    public function ledgerOpening(BankReconciliation $reconciliation): Money
    {
        $start = $this->openingBoundary($reconciliation);

        return $this->ledgerBalanceThrough($reconciliation, $start->copy()->subDay());
    }

    /**
     * The closing ledger balance: the ledger position through the last day of the
     * statement period, inclusive.
     *
     * Movements after to_date are excluded. They are real, and they are counted
     * by the account balance endpoint, but the bank has not issued a statement
     * covering them, so they cannot be part of the comparison this reconciliation
     * exists to make.
     */
    public function ledgerClosing(BankReconciliation $reconciliation): Money
    {
        return $this->ledgerBalanceThrough($reconciliation, $reconciliation->to_date->startOfDay());
    }

    /**
     * The debit and credit totals inside the statement period itself.
     *
     * These are the period's movements, not the ledger's whole history, and they
     * are what makes `ledger_opening + period_debits - period_credits =
     * ledger_closing` hold exactly for a debit-normal asset. Exposed because a
     * reconciliation that reports only its opening and closing balances asks the
     * reader to trust that they are consistent, and this is the arithmetic that
     * lets them check.
     */
    public function periodTotals(BankReconciliation $reconciliation): array
    {
        return $this->ledgerTotalsBetween(
            $reconciliation,
            $reconciliation->from_date->startOfDay(),
            $reconciliation->to_date->startOfDay(),
        );
    }

    /**
     * The complete reconciliation figure set.
     *
     * Assembled in one place so that the detail endpoint, the completion check
     * and any future report all read the same numbers from the same code. A
     * summary computed twice is two chances to disagree, and this project's
     * recurring lesson across seven phases is that the disagreement is the bug
     * nobody notices.
     *
     * @return array<string, Money>
     */
    public function summary(BankReconciliation $reconciliation): array
    {
        $period = $this->periodTotals($reconciliation);
        $clearing = $this->movements->clearingTotals($reconciliation);

        $ledgerOpening = $this->ledgerOpening($reconciliation);
        $ledgerClosing = $this->ledgerClosing($reconciliation);

        $unclearedDebits = $clearing['uncleared']['debit'];
        $unclearedCredits = $clearing['uncleared']['credit'];

        $reconciled = $ledgerClosing->minus($unclearedDebits)->plus($unclearedCredits);

        $difference = $reconciliation->statementClosing()->minus($reconciled);

        return [
            'ledger_opening' => $ledgerOpening,
            'period_debits' => $period['debit'],
            'period_credits' => $period['credit'],
            'ledger_closing' => $ledgerClosing,

            'statement_opening' => $reconciliation->statementOpening(),
            'statement_closing' => $reconciliation->statementClosing(),

            'cleared_debits' => $clearing['cleared']['debit'],
            'cleared_credits' => $clearing['cleared']['credit'],
            'uncleared_debits' => $unclearedDebits,
            'uncleared_credits' => $unclearedCredits,

            'total_movements' => $clearing['total'],
            'cleared_movements' => $clearing['cleared_count'],
            'uncleared_movements' => $clearing['uncleared_count'],

            'reconciled_balance' => $reconciled,
            'difference' => $difference,
        ];
    }

    /**
     * The closing statement balance of the reconciliation immediately before
     * this one for the same bank account, or null when there is none.
     *
     * "Immediately before" is defined by the period, not by insertion order: the
     * most recent completed reconciliation whose period ends before this one
     * begins. Restricting to completed *and not since reopened* is what makes
     * this an answer about settled history - a reconciliation the user has
     * reopened has said "this statement is not settled after all", and treating
     * its figure as the agreed carry-forward would contradict that.
     *
     * Returns null rather than inventing a prior reconciliation when the account
     * has never been reconciled, and the caller reports that honestly. The first
     * reconciliation of an account genuinely has no predecessor, and a zero
     * would be a figure this system would have made up.
     */
    public function previousReconciledClosing(BankReconciliation $reconciliation): ?Money
    {
        $previous = BankReconciliation::query()
            ->where('company_id', $reconciliation->company_id)
            ->where('bank_account_id', $reconciliation->bank_account_id)
            ->whereKeyNot($reconciliation->getKey())
            ->whereNotNull('completed_at')
            ->whereNull('reopened_at')
            ->whereDate('to_date', '<', $reconciliation->from_date->toDateString())
            ->orderByDesc('to_date')
            ->orderByDesc('id')
            ->first(['statement_closing_balance']);

        return $previous?->statementClosing();
    }

    /**
     * How far the user's statement opening balance sits from the one the previous
     * reconciliation ended on, or null when there is no predecessor to compare to.
     *
     * This is a warning, never an error. The value the user typed is not
     * overwritten and does not block completion: a bank can restate a figure, a
     * user can legitimately be reconciling from a statement that does not chain
     * perfectly to the last one, and silently substituting our number for theirs
     * would destroy the only information the user actually had - what the
     * statement says. The figure is surfaced so a human can judge it.
     */
    public function openingBalanceDifference(BankReconciliation $reconciliation): ?Money
    {
        $previous = $this->previousReconciledClosing($reconciliation);

        if ($previous === null) {
            return null;
        }

        return $reconciliation->statementOpening()->minus($previous);
    }

    /**
     * Can this reconciliation be completed right now?
     *
     * The only condition is a zero difference, checked with Money. Deliberately
     * not "zero uncleared movements": a reconciliation whose statement genuinely
     * includes an outstanding item proves its balance through the uncleared
     * adjustment, and demanding that nothing be outstanding would make it
     * impossible to reconcile a month in which a cheque is still unpresented.
     */
    public function canComplete(BankReconciliation $reconciliation): bool
    {
        return $this->summary($reconciliation)['difference']->isZero();
    }

    /**
     * The balance of this account through a date, from the existing ledger.
     *
     * Account-scope enforcement lives here rather than in each caller because
     * every caller needs it and it is the assertion that stops a reconciliation
     * from being attached to an account belonging to another tenant.
     *
     * @throws ValidationException
     */
    private function ledgerBalanceThrough(BankReconciliation $reconciliation, Carbon $through): Money
    {
        return $this->ledgerService()->balanceFor(
            $this->accountFor($reconciliation),
            null,
            $through,
        );
    }

    /**
     * @return array{debit: Money, credit: Money}
     */
    private function ledgerTotalsBetween(BankReconciliation $reconciliation, Carbon $from, Carbon $to): array
    {
        return $this->ledgerService()->totalsFor(
            $this->accountFor($reconciliation),
            $from,
            $to,
        );
    }

    /**
     * The first day of the statement period.
     *
     * Named separately because "the day before the period starts" is the off-by-one
     * that makes an opening balance silently include the period's first day, and
     * it is worth being able to point at the definition.
     */
    private function openingBoundary(BankReconciliation $reconciliation): Carbon
    {
        return $reconciliation->from_date->copy()->startOfDay();
    }

    /**
     * The ledger account this reconciliation is reconciling.
     *
     * Loaded with its bank details so eligibility can be judged in one query
     * rather than one per candidate account.
     *
     * @throws ValidationException
     */
    private function accountFor(BankReconciliation $reconciliation): Account
    {
        $account = $reconciliation->account()->with('bankAccount')->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'account_id' => 'The account this reconciliation was created for no longer exists.',
            ]);
        }

        if ($account->company_id !== $reconciliation->company_id) {
            throw ValidationException::withMessages([
                'account_id' => 'The account this reconciliation was created for does not belong to this company.',
            ]);
        }

        return $account;
    }

    private function ledgerService(): LedgerService
    {
        return app(LedgerService::class);
    }
}
