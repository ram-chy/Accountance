<?php

namespace App\Services\Accounting\FixedAssets;

use App\Enums\DepreciationMethod;
use App\Models\Company;
use App\Models\FixedAssetCategory;
use App\Models\User;
use App\Services\Accounting\TransactionAccountResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fixed asset categories: master data, no accounting effect.
 *
 * Every method here writes master data and nothing else. No journal, no period, no
 * amount - a category says how assets of a kind will be treated, and the treatment
 * is applied by FixedAssetService when an asset is created. Keeping the two apart is
 * what lets a category be edited freely: changing one affects the next asset created
 * and no existing asset, because every asset copied the relevant values onto its own
 * row at creation.
 *
 * The account columns are re-resolved on every write rather than merely checked for
 * existence, so an account that was deactivated since the category was last saved is
 * refused instead of silently accepted and failing at capitalisation time - which
 * would be the worst moment, because by then the asset exists and the user has a
 * half-finished register entry.
 *
 * Like TaxService and AccountService, retirement is deactivation rather than
 * deletion for a category that has been used, and outright deletion for one that has
 * not.
 */
class FixedAssetCategoryService
{
    public function __construct(
        private readonly TransactionAccountResolver $accounts,
    ) {}

    /**
     * Record a new category, active by definition.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(Company $company, User $actor, array $data): FixedAssetCategory
    {
        $this->assertAccounts($company, $data);

        return DB::transaction(function () use ($company, $actor, $data) {
            $category = new FixedAssetCategory;

            $category->fill([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'useful_life_months' => $data['useful_life_months'],
                'depreciation_method' => $data['depreciation_method'] ?? DepreciationMethod::StraightLine->value,
                'asset_account_id' => $data['asset_account_id'],
                'accumulated_depreciation_account_id' => $data['accumulated_depreciation_account_id'],
                'depreciation_expense_account_id' => $data['depreciation_expense_account_id'],
                'gain_on_disposal_account_id' => $data['gain_on_disposal_account_id'] ?? null,
                'loss_on_disposal_account_id' => $data['loss_on_disposal_account_id'] ?? null,
            ]);

            /*
             * is_active is not set here. The column defaults to true and a category is
             * never created inactive - creating one and immediately deactivating it is
             * two facts where one suffices, and it would put a category in the list of
             * unusable accounts that no asset could ever reference.
             */
            $category->company_id = $company->getKey();

            $category->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $category->save();

            return $category->refresh();
        });
    }

    /**
     * Edit a category.
     *
     * Does NOT reach into existing assets. The accounts and the useful life on this
     * row are defaults for future assets; an asset already created holds its own
     * copies, and rewriting them here would retroactively move the account an
     * asset's capitalisation and depreciation entries have been posting to for
     * months - splitting one asset's cost across two accounts with no record that it
     * happened.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(FixedAssetCategory $category, Company $company, User $actor, array $data): FixedAssetCategory
    {
        return DB::transaction(function () use ($category, $company, $actor, $data) {
            $fresh = $this->lock($category);

            /*
             * The account values are merged onto the stored row BEFORE they are
             * validated, because the rule being enforced is about the result and not
             * about the request. Validating the payload alone would refuse a partial
             * update that clears the gain account while leaving the loss account in
             * place - a valid category - and would accept one that clears both if the
             * keys happened to be omitted. The effective values are what will be
             * stored, so they are what gets checked.
             */
            $effective = [
                'asset_account_id' => $data['asset_account_id'] ?? $fresh->asset_account_id,
                'accumulated_depreciation_account_id' => $data['accumulated_depreciation_account_id'] ?? $fresh->accumulated_depreciation_account_id,
                'depreciation_expense_account_id' => $data['depreciation_expense_account_id'] ?? $fresh->depreciation_expense_account_id,
                'gain_on_disposal_account_id' => array_key_exists('gain_on_disposal_account_id', $data)
                    ? $data['gain_on_disposal_account_id']
                    : $fresh->gain_on_disposal_account_id,
                'loss_on_disposal_account_id' => array_key_exists('loss_on_disposal_account_id', $data)
                    ? $data['loss_on_disposal_account_id']
                    : $fresh->loss_on_disposal_account_id,
            ];

            $this->assertAccounts($company, $effective);

            /*
             * An inactive category is edited the same as an active one, because
             * reactivating it is a separate act and a user fixing a typo on a
             * retired category should not have to reactivate it first to do so. What
             * is refused is anything that would let an inactive category's new values
             * be used by an asset - which is handled by deactivate() refusing to leave
             * an asset pointing at it, not here.
             */
            $fresh->fill([
                'code' => $data['code'] ?? $fresh->code,
                'name' => $data['name'] ?? $fresh->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $fresh->description,
                'useful_life_months' => $data['useful_life_months'] ?? $fresh->useful_life_months,
                'depreciation_method' => $data['depreciation_method'] ?? $fresh->depreciation_method,
                'asset_account_id' => $effective['asset_account_id'],
                'accumulated_depreciation_account_id' => $effective['accumulated_depreciation_account_id'],
                'depreciation_expense_account_id' => $effective['depreciation_expense_account_id'],
                'gain_on_disposal_account_id' => $effective['gain_on_disposal_account_id'],
                'loss_on_disposal_account_id' => $effective['loss_on_disposal_account_id'],
            ]);

            $fresh->forceFill([
                'updated_by' => $actor->getKey(),
            ])->save();

            return $fresh->refresh();
        });
    }

    /**
     * Retire a category.
     *
     * Refuses while any asset still refers to it, even a draft one. A draft asset is
     * not in the ledger, but it IS a record that the company intends to own this
     * asset and has already chosen which accounts its cost will land in - retiring
     * the category out from under it would leave that asset configured from a
     * category that can no longer be inspected, and deactivating it would not help
     * because the values were copied at creation and would still be posting.
     *
     * @throws ValidationException
     */
    public function deactivate(FixedAssetCategory $category, User $actor): FixedAssetCategory
    {
        return DB::transaction(function () use ($category, $actor) {
            $fresh = $this->lock($category);

            if (! $fresh->is_active) {
                throw ValidationException::withMessages([
                    'is_active' => 'This category is already inactive.',
                ]);
            }

            if ($fresh->hasAssets()) {
                throw ValidationException::withMessages([
                    'fixed_asset_category_id' => sprintf(
                        'This category still has assets registered under it, starting with [%s], '
                        .'so it cannot be made inactive. Move or dispose of them first.',
                        (string) $fresh->assets()->orderBy('id')->value('asset_number')
                    ),
                ]);
            }

            $fresh->forceFill([
                'is_active' => false,
                'updated_by' => $actor->getKey(),
            ])->save();

            return $fresh->refresh();
        });
    }

    /**
     * Bring a retired category back into use.
     *
     * @throws ValidationException
     */
    public function activate(FixedAssetCategory $category, User $actor): FixedAssetCategory
    {
        return DB::transaction(function () use ($category, $actor) {
            $fresh = $this->lock($category);

            if ($fresh->is_active) {
                throw ValidationException::withMessages([
                    'is_active' => 'This category is already active.',
                ]);
            }

            /*
             * The accounts are re-validated on the way back in, and this is the one
             * place where that matters most: a category may have been retired for
             * years while its accounts were deactivated or their types were changed, and
             * reactivating it would otherwise put it back in the dropdown where a user
             * could select it for a new asset and then be refused at the very next
             * step. The resolver gives the precise reason an account is now unusable,
             * rather than this service inventing one.
             */
            $this->assertAccounts($fresh->company, [
                'asset_account_id' => $fresh->asset_account_id,
                'accumulated_depreciation_account_id' => $fresh->accumulated_depreciation_account_id,
                'depreciation_expense_account_id' => $fresh->depreciation_expense_account_id,
                'gain_on_disposal_account_id' => $fresh->gain_on_disposal_account_id,
                'loss_on_disposal_account_id' => $fresh->loss_on_disposal_account_id,
            ]);

            $fresh->forceFill([
                'is_active' => true,
                'updated_by' => $actor->getKey(),
            ])->save();

            return $fresh->refresh();
        });
    }

    /**
     * Delete a category outright - only possible while it has never been used.
     *
     * Two layers, and both are needed. The hasAssets() check produces the error a
     * user can act on; the foreign key is what actually enforces it, because a
     * category can acquire an asset between the check and the delete in a concurrent
     * request, and only the database can close that window.
     *
     * A retired category is still deletable if it was never used, which is right: it
     * is master data that was created in error, and requiring it to be deleted rather
     * than merely deactivated forever serves nobody.
     *
     * @throws ValidationException
     */
    public function delete(FixedAssetCategory $category, User $actor): void
    {
        DB::transaction(function () use ($category) {
            $fresh = $this->lock($category);

            if ($fresh->hasAssets()) {
                throw ValidationException::withMessages([
                    'fixed_asset_category_id' => 'This category has assets registered under it and cannot be deleted.',
                ]);
            }

            try {
                $fresh->delete();
            } catch (QueryException $e) {
                /*
                 * The foreign key fired, which means an asset appeared between the
                 * check above and this statement. Reported as the same refusal rather
                 * than as a server error, because from the user's point of view it IS
                 * the same refusal - they were told they cannot delete this category
                 * and they cannot.
                 */
                if ($this->isForeignKeyViolation($e)) {
                    throw ValidationException::withMessages([
                        'fixed_asset_category_id' => 'This category has assets registered under it and cannot be deleted.',
                    ]);
                }

                throw $e;
            }
        });
    }

    /**
     * Validate every account reference on the way in.
     *
     * Each is resolved through TransactionAccountResolver with the role it plays, so
     * the accumulated depreciation account is checked for a CREDIT normal balance
     * here rather than only at capitalisation - a contra asset that was configured
     * as debit-normal is a mistake worth reporting while the user is still on the
     * form that caused it.
     *
     * Gain and loss are resolved only when present, because a disposal needs only the
     * one that matches its result. They are NOT allowed to both be absent: a category
     * that could never account for a disposal is a category that will block one.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertAccounts(Company $company, array $data): void
    {
        if (isset($data['asset_account_id'])) {
            $this->accounts->fixedAsset($company, (int) $data['asset_account_id']);
        }

        if (isset($data['accumulated_depreciation_account_id'])) {
            $this->accounts->accumulatedDepreciation(
                $company,
                (int) $data['accumulated_depreciation_account_id']
            );
        }

        if (isset($data['depreciation_expense_account_id'])) {
            $this->accounts->depreciationExpense($company, (int) $data['depreciation_expense_account_id']);
        }

        if (! empty($data['gain_on_disposal_account_id'])) {
            $this->accounts->gainOnDisposal($company, (int) $data['gain_on_disposal_account_id']);
        }

        if (! empty($data['loss_on_disposal_account_id'])) {
            $this->accounts->lossOnDisposal($company, (int) $data['loss_on_disposal_account_id']);
        }

        $hasGain = ! empty($data['gain_on_disposal_account_id']);
        $hasLoss = ! empty($data['loss_on_disposal_account_id']);

        /*
         * Enforced against whatever set the caller passes, which is always the
         * EFFECTIVE set of accounts for this category: the request payload on create,
         * the merged row and payload on update, and the stored values on activate.
         *
         * It is deliberately not conditional on the keys being present. An earlier
         * version checked only when the payload mentioned one of them, which meant a
         * create that omitted both produced a category that could never record a
         * disposal - the exact row this rule exists to prevent.
         */
        if (! $hasGain && ! $hasLoss) {
            throw ValidationException::withMessages([
                'gain_on_disposal_account_id' => 'A category needs a gain-on-disposal account, a loss-on-disposal '
                    .'account, or both, so that disposing of one of its assets can be recorded.',
            ]);
        }
    }

    /**
     * Re-read the category under a row lock.
     *
     * The status transitions and the delete both read the row and then write it, and
     * without the lock two concurrent deactivations could both pass the is_active
     * check. This is the same lock-then-assert shape CreditDebitNotePostingService
     * uses on a note.
     */
    private function lock(FixedAssetCategory $category): FixedAssetCategory
    {
        return FixedAssetCategory::query()
            ->whereKey($category->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Is this a foreign key violation, as opposed to some other database error?
     *
     * Narrow on purpose: this catch block only converts a specific, expected
     * condition into a friendly message, and swallowing every QueryException would
     * turn a genuine database fault into a validation error the user could not
     * diagnose.
     */
    private function isForeignKeyViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23503'], true);
    }
}
