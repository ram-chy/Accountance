<?php

namespace App\Http\Requests\Transactions;

use App\Support\Money;
use Illuminate\Validation\Rule;

/**
 * Shared rules for money-shaped inputs on transactional documents.
 *
 * Invoice, bill, receipt and payment requests all accept decimal amounts, and
 * all of them need the same two things right: accept the values a client will
 * actually send, and never accept them as PHP floats.
 *
 * The second point is why these are `decimal:0,N` rules rather than `numeric`.
 * A JSON number has already passed through a double by the time PHP sees it, so
 * 'numeric' would accept 0.1 and quietly store the binary approximation. The
 * decimal rule forces the value to arrive as a string and be read as one.
 *
 * N is the *input* bound from config, not the stored scale. Extra precision is
 * rounded half-up rather than rejected (config/accounting.php, "Decimal Input
 * Tolerance"), and pinning the rule to the stored scale of 4 would contradict
 * that promise before Money::ofTolerant() ever ran.
 */
trait ValidatesTransactionAmounts
{
    /**
     * The widest decimal input a request will accept.
     */
    protected function maxInputDecimals(): int
    {
        return (int) config('accounting.rounding.max_input_decimals', Money::scale());
    }

    /**
     * A decimal amount rule set.
     *
     * @param  array<int, string>  $extra
     * @return array<int, mixed>
     */
    protected function decimalAmountRule(array $extra = []): array
    {
        return array_merge(['decimal:0,'.$this->maxInputDecimals()], $extra);
    }

    /**
     * A money amount that must exist and be greater than zero.
     *
     * @return array<int, mixed>
     */
    protected function positiveMoneyRule(): array
    {
        return $this->decimalAmountRule(['gt:0']);
    }

    /**
     * A money amount that may be zero but not negative.
     *
     * @return array<int, mixed>
     */
    protected function nonNegativeMoneyRule(): array
    {
        return $this->decimalAmountRule(['min:0']);
    }

    /**
     * An account id that must exist inside the active company.
     *
     * @return array<int, mixed>
     */
    protected function companyAccountRule(): array
    {
        return [
            'required',
            'integer',
            Rule::exists('accounts', 'id')->where('company_id', $this->activeCompanyId()),
        ];
    }

    abstract protected function activeCompanyId(): int;
}
