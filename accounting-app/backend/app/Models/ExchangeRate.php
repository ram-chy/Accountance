<?php

namespace App\Models;

use App\Support\Money;
use App\Support\Rate;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One dated quote for one currency pair, for one company.
 *
 * The effective-dated sibling of TaxRate, and deliberately built the same way: the
 * rate is history rather than a mutable current value, because a document's
 * exchange rate is a fact about the document and must not move afterwards.
 *
 * The direction convention is fixed and is the single most important thing to get
 * right when reading this class:
 *
 *     `rate` = units of `to_currency` per ONE unit of `from_currency`
 *
 * So (USD -> INR, 83.5) means one dollar bought eighty-three rupees, and
 * `convert()` multiplies. Nothing in this project divides by a rate on the
 * conversion path; Rate::reciprocal() exists for checking a rate a user entered
 * backwards rather than as a thing the arithmetic reaches for.
 *
 * No 'decimal:10' cast, for the reason TaxRate documents: Laravel passes the raw
 * driver string through, and a value that has been through a JSON response and
 * back can arrive with a different scale. rate() returns a Rate so no caller can do
 * arithmetic on a bare string.
 */
#[Fillable([
    'from_currency_id',
    'to_currency_id',
    'effective_date',
    'rate',
    'source',
])]
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    /**
     * is_active, created_by and updated_by are absent on purpose.
     *
     * Same reasoning as TaxRate and Tax: withdrawing a rate and recording who
     * configured one are service operations with their own checks, never payload
     * fields. created_by in particular must come from the authenticated user - an
     * audit row naming whoever the client said quoted the rate is worthless as
     * evidence.
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'from_currency_id');
    }

    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'to_currency_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function rate(): Rate
    {
        return Rate::of($this->rate);
    }

    /**
     * Convert a foreign amount into the target currency.
     *
     * The whole reason this class exists as more than a row: the multiply happens
     * here, through Rate, so that the value written to a journal line and the value
     * the database CHECK re-computes come from identical arithmetic. If conversion
     * were done at the call site, two callers could round it two ways and one of
     * them would eventually trip the constraint with a "wrong" amount.
     */
    public function convert(Money $amount): Money
    {
        return $this->rate()->applyTo($amount);
    }

    /**
     * Does this rate apply on a given day?
     *
     * Inclusive of effective_date and open-ended after it - there is no
     * effective_to, unlike TaxRate's. A rate does not end; it is superseded by a
     * later row for the same pair. Modelling an end date would suggest a rate
     * stops applying, and would require every backdated correction to open and
     * close a window, when all that is needed is to insert a newer row.
     *
     * $requireActive is passed rather than read from the model so the caller can
     * decide whether an inactive rate is being tested as a candidate or merely
     * inspected.
     */
    public function isEffectiveOn(Carbon|string $date, bool $requireActive = true): bool
    {
        if ($requireActive && ! $this->is_active) {
            return false;
        }

        $day = $date instanceof Carbon ? $date->toDateString() : $date;

        return $this->effective_date->toDateString() <= $day;
    }

    /**
     * A human-readable description of the pair, "USD/INR".
     *
     * Written source-then-target, matching the storage convention, so the string
     * and the numbers cannot appear to disagree about which way round the rate goes.
     */
    public function pair(): string
    {
        return $this->fromCurrency?->code.'/'.$this->toCurrency?->code;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    |
    | `onOrBefore` and `active` are the two halves of every resolution query, and
    | they are named here rather than written inline at each call site so that a
    * resolution is always assembled the same way. Ordering by effective_date
    * descending inside onOrBefore is not a convenience: it is the tie-break rule,
    * and putting it in the scope means a caller cannot forget it and take the
    * oldest rate on a day that has two.
    */

    public function scopeForPair(Builder $query, int $fromCurrencyId, int $toCurrencyId): Builder
    {
        return $query->where('from_currency_id', $fromCurrencyId)
            ->where('to_currency_id', $toCurrencyId);
    }

    public function scopeOnOrBefore(Builder $query, Carbon|string $date): Builder
    {
        $day = $date instanceof Carbon ? $date->toDateString() : $date;

        return $query->where('effective_date', '<=', $day)
            ->orderByDesc('effective_date');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
