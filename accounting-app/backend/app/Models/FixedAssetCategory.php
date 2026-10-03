<?php

namespace App\Models;

use App\Enums\DepreciationMethod;
use App\Support\Money;
use Database\Factories\FixedAssetCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class of asset - "Motor Vehicles", "Computer Equipment".
 *
 * Master data, and the source of every default a new asset starts with. It holds
 * no amounts of its own, which is what makes it safe to edit after assets exist:
 * changing a category's accounts or useful life changes what the NEXT asset will
 * do, and changes nothing about the assets already written down under the old
 * setting. That guarantee is the reason FixedAsset copies these values onto its
 * own row rather than reading them through a relation.
 *
 * Like Account and Tax there is no company-scoped global scope: company isolation
 * is enforced in the service and controller layer against the active company, so a
 * deliberate cross-company report remains writable. Every query in this phase
 * filters explicitly.
 */
#[Fillable([
    'code',
    'name',
    'description',
    'useful_life_months',
    'depreciation_method',
    /*
     * The account columns ARE fillable, unlike almost everything else on
     * FixedAsset. They are configuration a user chooses on a form - the same
     * position tax_account_id sits in on an invoice - and they are re-resolved
     * through TransactionAccountResolver on every write, so a stale or cross-company
     * id is refused rather than trusted.
     */
    'asset_account_id',
    'accumulated_depreciation_account_id',
    'depreciation_expense_account_id',
    'gain_on_disposal_account_id',
    'loss_on_disposal_account_id',
])]
class FixedAssetCategory extends Model
{
    /** @use HasFactory<FixedAssetCategoryFactory> */
    use HasFactory;

    /**
     * is_active, created_by and updated_by are absent on purpose.
     *
     * is_active is a lifecycle flag like accounts.is_active and taxes.is_active: it
     * changes through FixedAssetCategoryService so the transition is validated and
     * audited, and a new category is active by definition. The two audit columns
     * come from the authenticated user, never from a payload.
     */
    protected function casts(): array
    {
        return [
            'depreciation_method' => DepreciationMethod::class,
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<FixedAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'fixed_asset_category_id');
    }

    /**
     * The account an asset of this category is capitalised into.
     */
    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    /**
     * The contra account every depreciation entry of this category credits.
     *
     * TransactionAccountResolver::accumulatedDepreciation() is what guarantees this
     * is CREDIT-normal; the relation here cannot.
     */
    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }

    public function gainOnDisposalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gain_on_disposal_account_id');
    }

    public function lossOnDisposalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'loss_on_disposal_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Can this category be retired outright, or does history exist?
     *
     * The answer is what decides DELETE versus deactivate. An administrator deleting
     * a category that has assets under it would orphan every one of them, and the
     * foreign key would refuse the delete - so the service asks this first and
     * returns the friendlier error, and only uses the destroy path when the answer
     * is yes.
     */
    public function hasAssets(): bool
    {
        return $this->assets()->exists();
    }

    /**
     * The depreciable base of a hypothetical asset of this category.
     *
     * Exists so the category form can show what a monthly charge would be for the
     * figures currently entered, without a cost or salvage value having been stored
     * anywhere. The service uses the same arithmetic per asset, so the figure shown
     * and the figure charged come from one implementation rather than two.
     */
    public function monthlyChargeFor(Money $originalCost, Money $salvageValue): Money
    {
        return $originalCost
            ->minus($salvageValue)
            ->dividedBy(Money::ofInt($this->useful_life_months));
    }

    /**
     * @param  Builder<FixedAssetCategory>  $query
     * @return Builder<FixedAssetCategory>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
