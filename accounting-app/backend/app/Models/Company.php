<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * is_active is deliberately absent from $fillable.
 *
 * Deactivating a company has side effects - it moves every affected user's
 * default company and clears defaults for users left with none - so it is not a
 * plain attribute edit. Only CompanyService::deactivate() and ::activate() may
 * change it, and they use forceFill() for that reason. Leaving it fillable
 * would let any caller flip the flag with a single fill(), bypassing the
 * side effects and the companies.delete permission.
 *
 * currency_id is the company's FUNCTIONAL CURRENCY: the single currency every
 * posted base amount is denominated in, and the one every foreign amount is
 * converted into. Phase 14 made it real - it now carries a foreign key to
 * currencies - and removed the #[Hidden] attribute Phase 1 applied while the
 * column pointed at nothing.
 *
 * It remains fillable but is never set from a client payload. Choosing the base
 * currency is not a field edit: it changes what every existing balance means, so it
 * goes through CompanyCurrencyService under the companies.settings.update
 * permission, which also refuses the change once the company has posted any
 * foreign-currency document.
 *
 * The phase brief's "no seeder, no hidden currency insertion" is enforced here by
 * the absence of any default. A company with currency_id null books entirely in its
 * own amounts at an implicit rate of 1 - the Phase 13 behaviour, unchanged - and
 * cannot create a foreign-currency document until an administrator chooses one.
 */
#[Fillable([
    'name',
    'legal_name',
    'registration_number',
    'tax_number',
    'email',
    'phone',
    'website',
    'address_line_1',
    'address_line_2',
    'city',
    'state',
    'postal_code',
    'country_code',
    'timezone',
    'date_format',
    'currency_id',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'currency_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The company's base (functional) currency, or null when unconfigured.
     *
     * null is a supported state rather than a gap: see the note on currency_id
     * above. Every caller that needs a rate goes through ExchangeRateService, which
     * refuses to resolve one for a company with no base currency rather than
     * guessing.
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function hasBaseCurrency(): bool
    {
        return $this->currency_id !== null;
    }

    /**
     * ISO 3166-1 alpha-2 codes are compared, filtered and stored as a
     * two-character column, so 'us' and 'US' must not end up as distinct values.
     * Normalising on the model rather than in the form request means the value
     * is correct however the company was written - factory, console command,
     * seeder in a later phase, or HTTP request.
     */
    protected function countryCode(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : strtoupper(trim($value)),
        );
    }

    /**
     * Members of this company.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['is_default'])
            ->withTimestamps();
    }

    public function settings(): HasOne
    {
        return $this->hasOne(CompanySetting::class);
    }

    /**
     * Where this company posts realised FX gains and losses.
     *
     * Returns null rather than creating the row on access: reading a relationship
     * should not write, and an auto-created settings row would make "configured"
     * indistinguishable from "opened once".
     */
    public function fxSettings(): HasOne
    {
        return $this->hasOne(CompanyFxSetting::class);
    }

    /**
     * Whether the given user is a member of this company.
     *
     * Used by the policy on every company-scoped request, so it is written as a
     * single indexed existence check rather than a relationship load.
     */
    public function hasMember(User $user): bool
    {
        return $this->users()
            ->whereKey($user->getKey())
            ->exists();
    }

    /**
     * Whether the given user's default company is this one.
     */
    public function isDefaultFor(User $user): bool
    {
        return (bool) $this->users()
            ->whereKey($user->getKey())
            ->wherePivot('is_default', true)
            ->exists();
    }
}
