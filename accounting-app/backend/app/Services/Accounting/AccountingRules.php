<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Support\Money;

/**
 * The single place normal-balance and sign arithmetic is defined.
 *
 * The spec is explicit that "Asset -> Debit, Liability -> Credit" must not be
 * restated in controllers and reports. Two services and three reports each
 * restating the rule is how a revenue account ends up reported debit-normal in
 * one document and credit-normal in another. Everything asks this class.
 */
class AccountingRules
{
    /**
     * Resolve the effective normal balance for an account.
     *
     * An account's type implies a normal balance, but a contra account
     * (accumulated depreciation, sales returns, allowance for doubtful debts)
     * deliberately sits on the opposite side. Rather than inferring "contra"
     * from the name - which no accountant would accept as a rule - the
     * inversion is stored explicitly in accounts.normal_balance. A null means
     * "follow the account type".
     *
     * This is the seam the spec asks for in section 13: contra behaviour is
     * designed, not blocked, and no other code needs to know how it works.
     */
    public function normalBalanceFor(?Account $account): NormalBalance
    {
        if ($account === null) {
            throw new \InvalidArgumentException('Cannot resolve a normal balance without an account.');
        }

        return $account->normal_balance ?? $account->account_type->normalBalance();
    }

    public function normalBalanceForType(AccountType $type): NormalBalance
    {
        return $type->normalBalance();
    }

    /**
     * Net movement in raw debit-positive terms.
     *
     * This is the direction-independent figure: total debits minus total
     * credits. It is what the database sums and what an account statement
     * prints before deciding which column to print it in.
     */
    public function netMovement(Money $totalDebit, Money $totalCredit): Money
    {
        return $totalDebit->minus($totalCredit);
    }

    /**
     * Convert raw debit-positive movement into a signed amount on the account's
     * normal side.
     *
     * A positive result means the account sits on its normal side and its
     * balance has that value. A negative result means it is over-balanced on
     * the opposite side - an asset with more credits than debits, which is a
     * real state (an overdrawn bank account) and is reported as a negative
     * balance rather than being flipped to hide it.
     */
    public function signedBalance(Money $totalDebit, Money $totalCredit, NormalBalance $normalBalance): Money
    {
        $net = $this->netMovement($totalDebit, $totalCredit);

        return $normalBalance === NormalBalance::Credit ? $net->negate() : $net;
    }

    /**
     * Place a signed balance into the debit/credit columns a trial balance
     * prints.
     *
     * The rule is the inverse of signedBalance: a debit-normal account with a
     * negative balance belongs in the credit column, and vice versa. Putting
     * the magnitude in the right column is what keeps the trial balance
     * footed - the two column totals must agree to the cent.
     *
     * @return array{debit: Money, credit: Money}
     */
    public function splitForTrialBalance(Money $signedBalance, NormalBalance $normalBalance): array
    {
        if ($signedBalance->isZero()) {
            return ['debit' => Money::zero(), 'credit' => Money::zero()];
        }

        $isDebitSide = $signedBalance->isPositive();

        if ($normalBalance === NormalBalance::Credit) {
            $isDebitSide = ! $isDebitSide;
        }

        return $isDebitSide
            ? ['debit' => $signedBalance->absolute(), 'credit' => Money::zero()]
            : ['debit' => Money::zero(), 'credit' => $signedBalance->absolute()];
    }
}
