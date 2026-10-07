<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A currency: the ISO 4217 facts about money itself.
 *
 * GLOBAL REFERENCE DATA. There is no company_id, deliberately - see the create
 * migration for why a per-tenant copy of "the US dollar has two decimal places"
 * would be actively harmful.
 *
 * That does make this the one accounting model in the project with no tenant
 * boundary at all, so the two things a company can do to it are separated sharply:
 * it may READ every currency (a currency picker has to show what the company can
 * invoice in), and it may WRITE only through CurrencyService under an explicit
 * permission. Every other model in App\Models is company-scoped in its own right.
 */
#[Fillable([
    'code',
    'name',
    'symbol',
    'decimal_precision',
])]
class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory;

    /**
     * is_active is absent from the fillable, exactly as on Account and Tax.
     *
     * Retiring a currency is a CurrencyService method with its own validation and
     * its own audit row, because the transition has a consequence nothing else
     * does: documents already priced in that currency must stay readable, so the
     * reversible lifecycle has to end at deactivation. Letting a client flip the
     * flag in an update would skip that check.
     */
    protected function casts(): array
    {
        return [
            'decimal_precision' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $currency): void {
            /*
             * Upper-cased here rather than only in the request, because a currency
             * is created from three places - an HTTP request, a factory, and
             * whatever console command a deployment uses - and the database's
             * unique index is case-insensitive, so 'us' and 'US' cannot coexist but
             * one of them would be stored and then fail to match a document that
             * says 'US'. Normalising at the model makes the stored value canonical no
             * matter which door it came through.
             */
            $currency->code = mb_strtoupper(trim((string) $currency->code));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    |
    | Every one of these is inbound. A currency does not "have" the documents that
    | quote it - it is referenced by them - and naming them as belongsTo/hasMany
    | here would imply an ownership that does not exist. They are collected into a
    | single method rather than seven because the callers that need them are the
    | reporting and control queries, which ask "what uses this currency" as one
    | question.
    */

    public function ratesFrom(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'from_currency_id');
    }

    public function ratesTo(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'to_currency_id');
    }

    public function companiesUsingItAsBase(): HasMany
    {
        return $this->hasMany(Company::class, 'currency_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'currency_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    /**
     * The number of decimal places this currency's amounts are written with.
     *
     * Derived, not stored as a string: a "format" built by string concatenation
     * invites the 1/2/3 branching into a request, a resource and a report, and
     * three copies of a switch disagree eventually.
     */
    public function formatAmount(Money $amount): string
    {
        return number_format((float) $amount->toDatabase(), $this->decimal_precision, '.', ',');
    }

    /**
     * A short display label, "USD - US Dollar".
     *
     * The symbol is deliberately not in it. A symbol is a presentation detail that
     * varies by locale and font, and two currencies share several of them ($, ¥,
     * kr), so it cannot identify a currency in a picker or a report column header.
     */
    public function label(): string
    {
        return $this->code.' - '.$this->name;
    }

    /**
     * The major unit's name, for a report column header.
     *
     * "US Dollar", not "$". A base-currency column on a trial balance headed "$"
     * is ambiguous the moment a company holds two dollar-sign currencies, which is
     * the situation this phase exists to handle.
     */
    public function majorUnitName(): string
    {
        return $this->name;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    |
    | A company-scoped model has no global scope in this project - company
    | isolation is enforced in the service and controller layer against the active
    | company, so a deliberate cross-company report stays writable. The scopes here
    | are therefore about the currency's own lifecycle rather than about tenancy.
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeWithCode(Builder $query, string $code): Builder
    {
        return $query->where('code', mb_strtoupper($code));
    }
}
