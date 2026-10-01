<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['first_name', 'last_name', 'email', 'mobile_no', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the JWT identifier.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Get the JWT custom claims.
     *
     * `pv` is the user's password-version fingerprint, consumed by
     * `App\Http\Middleware\EnsureTokenIsFresh` to reject tokens that were
     * issued before a password change. It is a one-way digest, never the hash
     * itself, so a token payload leaks no credential material.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'pv' => $this->passwordVersion(),
        ];
    }

    /**
     * Companies this user belongs to.
     *
     * A user may belong to several companies and exactly one may be their
     * default, so this is many-to-many with an `is_default` pivot flag.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['is_default'])
            ->withTimestamps();
    }

    /**
     * The user's default company, or null when they have none.
     *
     * A deactivated company is excluded: it must not be used as an active
     * context, so it must not be silently handed back as the default either.
     */
    public function defaultCompany(): ?Company
    {
        return $this->companies()
            ->wherePivot('is_default', true)
            ->where('companies.is_active', true)
            ->first();
    }

    /**
     * Constrain a query to companies this user belongs to.
     */
    public function scopeMemberOfCompany(Builder $query, Company $company): Builder
    {
        return $query->whereHas(
            'companies',
            fn (Builder $q) => $q->whereKey($company->getKey()),
        );
    }

    /**
     * A short, non-reversible fingerprint of the stored password hash.
     *
     * Embedded in every token as the `pv` (password version) claim. Because the
     * hash changes whenever the password changes, a token minted before the
     * change no longer matches and is rejected. This is how a password change or
     * a password reset revokes every previously issued token, including tokens
     * that were never seen by this server and therefore cannot be blacklisted.
     *
     * A SHA-256 digest truncated to 32 characters is one-way; the raw bcrypt
     * hash is never exposed.
     */
    public function passwordVersion(): string
    {
        return substr(hash('sha256', (string) $this->getAuthPassword()), 0, 32);
    }
}
