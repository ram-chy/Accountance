<?php

namespace App\Services\Accounting\Currency;

use App\Models\Currency;
use App\Support\Money;
use App\Support\Rate;

/**
 * The currency a document is being transacted in, and the rate that converts it.
 *
 * A small immutable bundle rather than three loose return values, because the three
 * travel together and separating them is how they get separated in practice: a caller
 * that resolved a rate and then re-derived a currency would have two opportunities
 * to disagree, and the disagreement would appear as a document whose rate is not the
 * rate of its own currency.
 *
 * "Foreign" and "base" are represented by null rate and Rate::one() respectively
 * rather than by a boolean, so that multiplying by the rate is always correct and
 * never a special case:
 *
 *   base document     currency = null        rate = Rate::one()
 *   foreign document  currency = a Currency  rate = the resolved snapshot
 *
 * That is why a base-currency document produces exactly the journal it produced in
 * Phase 13 - multiplying by 1 is the identity - and why the whole existing test suite
 * keeps passing with no behavioural change.
 */
final readonly class TransactionCurrency
{
    private function __construct(
        public ?Currency $currency,
        public Rate $rate,
        public ?Currency $baseCurrency = null,
    ) {}

    /**
     * A document in the company's own base currency: no conversion, no rate.
     *
     * $baseCurrency is passed rather than looked up later because account
     * compatibility needs it: an account declared as "IDR only" must accept a
     * base-currency document, and to know that, the context has to be able to say
     * which currency "base" actually means here. Passing null is legitimate and
     * means the company has not configured a base currency at all, in which case
     * nothing can be declared against it and every account accepts everything.
     */
    public static function base(?Currency $baseCurrency = null): self
    {
        return new self(null, Rate::one(), $baseCurrency);
    }

    /**
     * A document in a foreign currency, at a resolved rate.
     */
    public static function foreign(Currency $currency, Rate $rate, ?Currency $baseCurrency = null): self
    {
        return new self($currency, $rate, $baseCurrency);
    }

    /**
     * Was this document transacted in a currency other than the company's base?
     *
     * Note that a document in the base currency has a rate of 1 but a null currency:
     * there was nothing to quote, and storing a rate of 1 would claim otherwise.
     */
    public function isForeign(): bool
    {
        return $this->currency !== null;
    }

    /**
     * The currency an account must be declared in to accept this document.
     *
     * For a foreign document that is the transaction currency. For a base document it
     * is the company's base currency - NOT null.
     *
     * This distinction is the whole reason the class carries both fields. Passing null
     * for a base document would be read by Account::acceptsCurrency() as "no
     * restriction", so a base-currency document would be accepted by a foreign
     * currency-specific account too, and the account's currency restriction would be
     * unenforceable for exactly the case it exists to cover.
     */
    public function effectiveCurrency(): ?Currency
    {
        return $this->currency ?? $this->baseCurrency;
    }

    /**
     * The currency code for a report column or a journal line, or null.
     */
    public function code(): ?string
    {
        return $this->currency?->code;
    }

    /**
     * The code an account-compatibility message should quote.
     */
    public function effectiveCode(): string
    {
        return $this->effectiveCurrency()?->code ?? 'base currency';
    }

    /**
     * Convert a transaction-currency amount into the company's base currency.
     */
    public function convertToBase(Money $amount): Money
    {
        return $this->rate->applyTo($amount);
    }

    /**
     * The rate to persist, or null.
     *
     * A base-currency document persists NULL, which is what the schema and the
     * database CHECK on journal_lines both expect, and what keeps "no rate was
     * quoted" distinguishable from "a rate of 1 was quoted".
     */
    public function rateToPersist(): ?string
    {
        return $this->isForeign() ? $this->rate->toDatabase() : null;
    }
}
