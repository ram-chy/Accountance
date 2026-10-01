<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 * currency_id is fillable so a later phase can set it through the service, but
 * it is excluded from request validation and hidden from responses until the
 * currencies table exists.
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
#[Hidden(['currency_id'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
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
