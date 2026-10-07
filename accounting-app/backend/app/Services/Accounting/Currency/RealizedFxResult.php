<?php

namespace App\Services\Accounting\Currency;

use App\Support\Money;

/**
 * The difference between what a balance carried and what settling it actually moved.
 *
 * WHY THE SIGN IS DEFINED THE WAY IT IS
 *
 * The difference is settlement minus carrying: what the money was worth when it was
 * recognised, subtracted from what it was worth when it was collected. A positive
 * difference means the company ended up with MORE base currency than the balance was
 * carrying, which is a gain.
 *
 * That reads backwards for a moment, and it is worth being explicit about why, because
 * getting it the other way round posts FX results to the wrong side and every
 * individual entry still balances:
 *
 *   Invoice for 100 USD at 16,500   -> receivable carries 1,650,000 IDR
 *   Collected at 17,000             -> cash arrives worth    1,700,000 IDR
 *   difference = 1,700,000 - 1,650,000 = +50,000            -> GAIN
 *
 * The receivable is credited at its carrying value and the excess 50,000 is credited
 * to the gain account. If the rate had fallen to 15,000 the difference would be
 * -150,000, and the shortfall is debited to the loss account. Same subtraction,
 * opposite direction.
 */
final readonly class RealizedFxResult
{
    private function __construct(
        public Money $settlementBase,
        public Money $carryingBase,
    ) {}

    public static function between(Money $settlementBase, Money $carryingBase): self
    {
        return new self($settlementBase, $carryingBase);
    }

    /**
     * settlement base amount minus carrying base amount.
     */
    public function difference(): Money
    {
        return $this->settlementBase->minus($this->carryingBase);
    }

    /**
     * Did the company gain, lose, or break even?
     */
    public function isGain(): bool
    {
        return $this->difference()->isPositive();
    }

    public function isLoss(): bool
    {
        return $this->difference()->isNegative();
    }

    /**
     * Is there an FX posting at all?
     *
     * Separate from isGain/isLoss because "no FX line" is a third case, not the
     * absence of the other two. A settlement at exactly the carrying rate produces a
     * balanced journal with no FX account touched - which is the correct outcome and
     * the one a test asserting `isLoss()` would mistake for a bug.
     */
    public function isNone(): bool
    {
        return $this->difference()->isZero();
    }

    /**
     * The magnitude to post, always positive.
     *
     * The direction lives in isGain()/isLoss() and is applied by which column the
     * posting service fills. Returning an absolute value means the posting code
     * cannot accidentally debit a negative amount, which the journal CHECK forbids
     * and which would otherwise surface as a constraint violation on the posting path
     * rather than as the logic error it is.
     */
    public function amount(): Money
    {
        return $this->difference()->absolute();
    }

    /**
     * The base amount to clear the balance with.
     *
     * The carrying value, not the settlement value. The settlement amount is what the
     * money is worth now; the carrying value is what the balance on the account is
     * worth, and clearing a balance means removing exactly what is on it. Putting the
     * settlement amount on the receivable instead would leave a residual balance equal
     * to the FX result, which is a real account balance with no invoice behind it.
     */
    public function amountToClear(): Money
    {
        return $this->carryingBase;
    }

    /**
     * A one-line description for the journal line's memo.
     */
    public function description(): string
    {
        return match (true) {
            $this->isGain() => 'Realised foreign exchange gain',
            $this->isLoss() => 'Realised foreign exchange loss',
            default => 'No realised foreign exchange difference',
        };
    }
}
