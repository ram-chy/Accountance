<?php

namespace App\Services\Accounting\FixedAssets;

use App\Enums\DepreciationMethod;
use App\Enums\DocumentNumberType;
use App\Enums\FixedAssetAcquisitionMethod;
use App\Enums\FixedAssetStatus;
use App\Enums\JournalSource;
use App\Exceptions\ConflictException;
use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\User;
use App\Services\Accounting\DocumentNumberSequence;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\TransactionAccountResolver;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The fixed asset register: recording, editing and capitalising assets.
 *
 * THE LIFECYCLE, AND WHICH HALF OF IT LIVES HERE
 *
 *   DRAFT ──capitalise()──> ACTIVE ──depreciate()──> FULLY_DEPRECIATED
 *                                └──dispose()──> DISPOSED
 *
 * create, update and delete cover the draft half. capitalise() is here because the
 * capitalisation entry is the asset's first journal and is the point at which the
 * asset becomes part of the permanent record. Depreciation and disposal have their
 * own services because each is a separate transaction with its own entry shape, its
 * own permission and its own concurrency question - see
 * FixedAssetDepreciationService and FixedAssetDisposalService.
 *
 * THERE IS NO UN-CAPITALISE
 *
 * Once an asset is ACTIVE it cannot go back to DRAFT, and there is no route that
 * tries. Every other document in this application has the same property - a posted
 * document is immutable and the only correction is a reversing entry - and the
 * reason is the same: the cost is in the ledger, and the ledger has no delete.
 *
 * The consequence for the user is that a mistake in an asset's cost is fixed by
 * recording the correct facts and then disposing of the asset, or by an adjustment
 * journal. This is a real limitation of the module rather than an oversight, and it
 * is recorded in PHASE_12_REPORT.md alongside the supplier-credit gap.
 *
 * EVERY CATEGORY VALUE IS COPIED, NEVER JOINED
 *
 * useful_life_months, depreciation_method and the five account columns are read from
 * the category once, at creation, and then belong to this asset. Every later journal
 * for this asset reads them from here.
 *
 * This is the single most consequential decision in the phase. Reading them live
 * from the category would mean that editing a category's accumulated depreciation
 * account would silently move the destination of the next ninety charge entries for
 * every asset under it - splitting one asset's cost between two accounts, with the
 * capitalisation entry in one and the write-downs in the other, and nothing recording
 * that this happened. Copying makes that class of error unrepresentable.
 */
class FixedAssetService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly JournalPostingService $posting,
        private readonly TransactionAccountResolver $accounts,
        private readonly DocumentNumberSequence $numbers,
    ) {}

    /**
     * Record a draft asset.
     *
     * The acquisition method is a PARAMETER, not a payload field, and the reason is
     * the same one CashBankTransactionController gives for having three create routes
     * rather than one: the method decides what kind of account the credit side of the
     * capitalisation entry may be, so a payload that carried both could name a
     * liability account and claim the method was CASH. Taking it from the route means
     * the client cannot express that disagreement, and the account is then validated
     * against a value it did not choose.
     *
     * A draft has no journal and no ledger effect whatsoever. It is invisible to every
     * report, it carries no carrying value, and deleting it leaves no trace - which is
     * exactly why it may be created and discarded freely.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(
        Company $company,
        User $actor,
        FixedAssetAcquisitionMethod $method,
        array $data
    ): FixedAsset {
        /*
         * Resolved before the transaction opens. Every one of these is a read that
         * either passes or fails independently of the write, and holding a transaction
         * open across six account lookups to produce a 422 would be the wrong trade.
         * They are re-resolved at capitalisation, because an account deactivated in
         * the meantime must not be posted to.
         */
        $category = $this->resolveCategory($company, (int) $data['fixed_asset_category_id']);
        $acquisitionAccount = $this->accounts->acquisitionAccount(
            $company,
            (int) $data['acquisition_account_id'],
            $method
        );

        $this->assertCategoryAccountsUsable($category);
        $this->assertCostAndSalvage($data['original_cost'], $data['salvage_value'] ?? 0);

        return DB::transaction(function () use ($company, $actor, $method, $data, $category, $acquisitionAccount) {
            $asset = new FixedAsset;

            $asset->fill([
                'fixed_asset_category_id' => $category->getKey(),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'acquisition_date' => $data['acquisition_date'],
                'original_cost' => $data['original_cost'],
                'salvage_value' => $data['salvage_value'] ?? 0,
                'depreciation_start_date' => $data['depreciation_start_date'],
                'acquisition_account_id' => $acquisitionAccount->getKey(),
            ]);

            /*
             * Everything the client could not supply. asset_number comes from the
             * company-scoped FA- sequence and is never reused. The category's
             * depreciation configuration and its five accounts are copied here, in
             * this transaction, so that the snapshot and the asset it belongs to are
             * committed together or not at all - a half-written asset with no accounts
             * could not be capitalised and could not be diagnosed.
             *
             * The category is re-read under the lock and checked again, because it may
             * have been deactivated between the resolve above and this write.
             */
            $freshCategory = $this->lockCategory($category);

            if (! $freshCategory->is_active) {
                throw ValidationException::withMessages([
                    'fixed_asset_category_id' => 'This category is inactive and cannot be used for a new asset.',
                ]);
            }

            $asset->company_id = $company->getKey();
            $asset->asset_number = $this->numbers->nextFor($company, DocumentNumberType::FixedAsset);

            $asset->forceFill([
                'useful_life_months' => $freshCategory->useful_life_months,
                'depreciation_method' => DepreciationMethod::StraightLine->value,
                'asset_account_id' => $freshCategory->asset_account_id,
                'accumulated_depreciation_account_id' => $freshCategory->accumulated_depreciation_account_id,
                'depreciation_expense_account_id' => $freshCategory->depreciation_expense_account_id,
                'gain_on_disposal_account_id' => $freshCategory->gain_on_disposal_account_id,
                'loss_on_disposal_account_id' => $freshCategory->loss_on_disposal_account_id,
                'acquisition_method' => $method->value,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            /*
             * status defaults to DRAFT at the column level and is deliberately not
             * written here. Setting it explicitly to DRAFT would be a second place
             * that says what a new asset's status is, and the column default already
             * says it - the one statement of that fact.
             */
            $asset->save();

            return $asset->refresh();
        });
    }

    /**
     * Edit a draft asset.
     *
     * Refuses anything that is not a draft. Not as a courtesy - as the only thing
     * standing between a user and a capitalised asset whose cost has been changed
     * under a journal that already posted it.
     *
     * The fields that MAY change are only the descriptive ones and the schedule. The
     * cost cannot, and neither can the acquisition account or method: all three are
     * part of what the capitalisation entry asserts, and this method is not reachable
     * once that entry exists.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ConflictException when the asset is no longer a draft
     * @throws ValidationException
     */
    public function update(FixedAsset $asset, Company $company, User $actor, array $data): FixedAsset
    {
        return DB::transaction(function () use ($asset, $company, $actor, $data) {
            $fresh = $this->lock($asset);

            if (! $fresh->status->isDraft()) {
                throw new ConflictException(
                    message: 'A capitalised fixed asset cannot be edited.',
                    errors: [
                        'status' => [sprintf(
                            'Asset [%s] has been capitalised and can no longer be edited. '
                            .'Its cost, acquisition method and accounts are part of a posted journal.',
                            $fresh->asset_number
                        )],
                    ],
                );
            }

            if (array_key_exists('original_cost', $data) || array_key_exists('salvage_value', $data)) {
                $this->assertCostAndSalvage(
                    $data['original_cost'] ?? $fresh->original_cost,
                    $data['salvage_value'] ?? $fresh->salvage_value
                );
            }

            /*
             * The category may be CHANGED on a draft - an asset misfiled under the
             * wrong category before it was ever capitalised should not require a
             * disposal - but changing it re-copies the five accounts and the useful
             * life, because on a draft nothing has been posted and so nothing is
             * being contradicted. That is the one condition under which rewriting the
             * snapshot is correct rather than dangerous, and the isDraft() check above
             * is what makes it safe.
             */
            if (array_key_exists('fixed_asset_category_id', $data)
                && (int) $data['fixed_asset_category_id'] !== $fresh->fixed_asset_category_id) {
                $category = $this->resolveCategory($company, (int) $data['fixed_asset_category_id']);

                $fresh->fixed_asset_category_id = $category->getKey();

                $fresh->useful_life_months = $category->useful_life_months;
                $fresh->depreciation_method = DepreciationMethod::StraightLine->value;
                $fresh->asset_account_id = $category->asset_account_id;
                $fresh->accumulated_depreciation_account_id = $category->accumulated_depreciation_account_id;
                $fresh->depreciation_expense_account_id = $category->depreciation_expense_account_id;
                $fresh->gain_on_disposal_account_id = $category->gain_on_disposal_account_id;
                $fresh->loss_on_disposal_account_id = $category->loss_on_disposal_account_id;
            }

            if (array_key_exists('acquisition_account_id', $data)) {
                $account = $this->accounts->acquisitionAccount(
                    $company,
                    (int) $data['acquisition_account_id'],
                    $fresh->acquisition_method
                );

                $fresh->acquisition_account_id = $account->getKey();
            }

            $fresh->fill([
                'name' => $data['name'] ?? $fresh->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $fresh->description,
                'serial_number' => array_key_exists('serial_number', $data) ? $data['serial_number'] : $fresh->serial_number,
                'supplier_reference' => array_key_exists('supplier_reference', $data) ? $data['supplier_reference'] : $fresh->supplier_reference,
                'acquisition_date' => $data['acquisition_date'] ?? $fresh->acquisition_date,
                'salvage_value' => $data['salvage_value'] ?? $fresh->salvage_value,
                'depreciation_start_date' => $data['depreciation_start_date'] ?? $fresh->depreciation_start_date,
            ]);

            /*
             * original_cost is excluded from the fill above deliberately and set here,
             * so that the salvage-versus-cost check above has already compared the
             * NEW cost against the salvage value being kept - and so that a reader can
             * see the cost is editable but not by accident.
             */
            if (array_key_exists('original_cost', $data)) {
                $fresh->original_cost = $data['original_cost'];
            }

            $fresh->forceFill([
                'updated_by' => $actor->getKey(),
            ])->save();

            return $fresh->refresh();
        });
    }

    /**
     * Discard a draft asset.
     *
     * Only a draft. The check is an assertion rather than a flag because the whole
     * point of a capitalised asset is that its cost is in the ledger, and there is no
     * operation anywhere in this application that removes a cost from the ledger by
     * deleting a record.
     *
     * @throws ConflictException
     */
    public function delete(FixedAsset $asset, User $actor): void
    {
        DB::transaction(function () use ($asset) {
            $fresh = $this->lock($asset);

            if (! $fresh->status->isDraft()) {
                throw new ConflictException(
                    message: 'A capitalised fixed asset cannot be deleted.',
                    errors: [
                        'status' => [sprintf(
                            'Asset [%s] is [%s] and its cost is in the ledger, so it cannot be deleted. '
                            .'Dispose of it instead, which removes the cost and records the result.',
                            $fresh->asset_number,
                            $fresh->status->value
                        )],
                    ],
                );
            }

            try {
                $fresh->delete();
            } catch (QueryException $e) {
                /*
                 * A depreciation row exists, which can only happen if the asset was
                 * capitalised between the status check and this statement. Reported as
                 * the conflict it is, since that is what it is.
                 */
                if (in_array($e->getCode(), ['23000', '23503'], true)) {
                    throw new ConflictException(
                        message: 'This fixed asset has already been capitalised and cannot be deleted.',
                        errors: ['status' => ['This fixed asset can no longer be deleted.']],
                    );
                }

                throw $e;
            }
        });
    }

    /**
     * Put an asset's cost into the ledger and start its life.
     *
     * THE ENTRY
     *
     *   CASH             Dr Fixed Asset       original cost
     *                       Cr Cash or Bank    original cost
     *
     *   SUPPLIER_CREDIT  Dr Fixed Asset       original cost
     *                       Cr Accounts Payable  original cost
     *
     * Both are two lines on one account pair, and which pair is decided by the
     * asset's stored acquisition method - never by the account id, and never by
     * anything in the request, because by the time this runs the method is a fact
     * about the asset rather than a choice being made.
     *
     * EVERY ACCOUNT IS RE-RESOLVED HERE, NOT TRUSTED FROM DRAFT TIME
     *
     * The draft resolved all five accounts when it was saved. An account may have
     * been deactivated since - or had its type or normal balance changed - and this
     * entry is being made now. The accumulated depreciation account in particular is
     * re-checked for a CREDIT normal balance, because a debit-normal contra asset
     * would post this entry correctly and then make every subsequent depreciation
     * charge INCREASE the asset on the balance sheet. Nothing downstream would
     * complain: the trial balance would still foot.
     *
     * @throws ConflictException when the asset is not a draft
     * @throws ValidationException
     */
    public function capitalise(FixedAsset $asset, User $actor): FixedAsset
    {
        return DB::transaction(function () use ($asset, $actor) {
            /*
             * The asset row is locked FIRST and before anything is decided. Two
             * concurrent capitalise requests for one asset would both read DRAFT, both
             * build an entry and both post it without this; the second would be refused
             * by the status check below and by nothing else. This is the same
             * lock-then-assert order every write path in this application uses.
             */
            $fresh = $this->lock($asset);

            if (! $fresh->status->isDraft()) {
                throw new ConflictException(
                    message: 'This fixed asset has already been capitalised.',
                    errors: [
                        'status' => [sprintf(
                            'Asset [%s] is already [%s] and has already been capitalised.',
                            $fresh->asset_number,
                            $fresh->status->value
                        )],
                    ],
                );
            }

            $cost = $fresh->originalCostAmount();

            if (! $cost->isPositive()) {
                throw ValidationException::withMessages([
                    'original_cost' => 'A fixed asset must cost more than zero to be capitalised.',
                ]);
            }

            // The five category-sourced accounts, re-validated as described above.
            $assetAccount = $this->accounts->fixedAsset($fresh->company, $fresh->asset_account_id);
            $this->accounts->accumulatedDepreciation(
                $fresh->company,
                $fresh->accumulated_depreciation_account_id
            );

            /*
             * The money side, dispatched on the asset's own method. This is the one
             * call that decides which side of the entry is which, and it asks
             * cashBank() or payable() accordingly - so the "is this account really a
             * cash account" rule is the one already written for cash and bank
             * transactions rather than a fourth copy of it here.
             */
            $creditAccount = $this->accounts->acquisitionAccount(
                $fresh->company,
                $fresh->acquisition_account_id,
                $fresh->acquisition_method
            );

            $journal = $this->journals->createDraft(
                $fresh->company,
                $actor,
                [
                    /*
                     * The asset's depreciation_start_date, not its acquisition date.
                     * The period it posts into is the period the asset entered service
                     * as far as the register is concerned, and using acquisition_date
                     * for an asset bought in March and capitalised in April would put
                     * the cost into March's period - which may already be closed.
                     */
                    'journal_date' => $fresh->depreciation_start_date->toDateString(),

                    'description' => sprintf('Capitalisation of fixed asset %s', $fresh->asset_number),

                    'reference' => $fresh->supplier_reference ?: $fresh->asset_number,

                    'source_type' => JournalSource::FixedAsset->value,
                    'source_id' => $fresh->getKey(),

                    'lines' => [
                        [
                            'account_id' => $assetAccount->getKey(),
                            'description' => 'Fixed asset',
                            'debit' => $cost->toDatabase(),
                            'credit' => '0',
                        ],
                        [
                            'account_id' => $creditAccount->getKey(),
                            'description' => $fresh->acquisition_method->requiresCashBankAccount()
                                ? 'Cash or bank'
                                : 'Accounts payable',
                            'debit' => '0',
                            'credit' => $cost->toDatabase(),
                        ],
                    ],
                ]
            );

            $this->posting->post($journal, $actor, 'journal_date');

            /*
             * Status, journal pointer and audit columns in ONE statement, so there is
             * no instant at which the asset claims to be capitalised without its
             * journal. The schema proves part of that invariant - a non-draft row must
             * have a capitalised_at - and cannot prove the journal half, because
             * MySQL will not accept a CHECK on a column carrying ON DELETE SET NULL.
             * See the note in the migration; this write is where the full guarantee is
             * made.
             */
            $fresh->forceFill([
                'status' => FixedAssetStatus::Active->value,
                'journal_id' => $journal->getKey(),
                'capitalised_at' => now(),
                'capitalised_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            return $fresh->refresh();
        });
    }

    /**
     * The asset's carrying value, from the posted depreciation rows.
     *
     * Used by the disposal service and the resource. Delegated here rather than read
     * from the model in both places so that "what is this asset worth" has one answer,
     * and so that the definition is stated next to the schedule arithmetic that
     * maintains the invariant it depends on.
     */
    public function carryingAmount(FixedAsset $asset): Money
    {
        return $asset->carryingAmount();
    }

    /**
     * Are the category's own accounts usable?
     *
     * The category is normally guaranteed healthy by FixedAssetCategoryService, which
     * validates all five accounts on create, on update, and before deactivating. This
     * method covers the gap that leaves: a category written outside that service - by a
     * factory, a seeder, a console command, or a migration - can carry an account that
     * is the wrong type or the wrong normal balance.
     *
     * Without this check such a category produces an asset that saves perfectly and then
     * refuses to capitalise, with the error naming an accumulated depreciation account
     * the user never selected and cannot see on the asset form. Validating here turns
     * that into a 422 at the moment the problem is introduced, which is also the moment
     * the user can still do something about it.
     *
     * It is a re-read rather than a copy of the category's own assertion on purpose:
     * the resolver is the single authority on what an account of each kind may be, and
     * duplicating those rules in a second place would let them drift.
     *
     * @throws ValidationException
     */
    private function assertCategoryAccountsUsable(FixedAssetCategory $category): void
    {
        $this->accounts->fixedAsset($category->company, (int) $category->asset_account_id);
        $this->accounts->accumulatedDepreciation(
            $category->company,
            (int) $category->accumulated_depreciation_account_id
        );
        $this->accounts->depreciationExpense(
            $category->company,
            (int) $category->depreciation_expense_account_id
        );

        /*
         * The disposal accounts are optional, so each is validated only when the
         * category has one. The resolver rejects a null id, which would be a false
         * alarm here - a category with neither is valid, and the one case where a
         * disposal cannot be booked.
         */
        if ($category->gain_on_disposal_account_id !== null) {
            $this->accounts->gainOnDisposal($category->company, (int) $category->gain_on_disposal_account_id);
        }

        if ($category->loss_on_disposal_account_id !== null) {
            $this->accounts->lossOnDisposal($category->company, (int) $category->loss_on_disposal_account_id);
        }
    }

    /**
     * Is the cost usable?
     *
     * The CHECK constraints on the table enforce the same three rules, and they are
     * checked here as well because a 422 naming the field is a usable answer and a
     * database error is not. The useful life is checked by the category's own
     * constraint and is not an asset-level concern - the asset copies it.
     *
     * @throws ValidationException
     */
    private function assertCostAndSalvage(mixed $cost, mixed $salvage): void
    {
        $errors = [];

        $costMoney = Money::of((string) $cost);
        $salvageMoney = Money::of((string) $salvage);

        if (! $costMoney->isPositive()) {
            $errors['original_cost'] = 'A fixed asset must cost more than zero.';
        }

        if ($salvageMoney->isNegative()) {
            $errors['salvage_value'] = 'A salvage value cannot be negative.';
        } elseif ($costMoney->isPositive() && $salvageMoney->greaterThan($costMoney)) {
            /*
             * Straight-line cannot express this. The depreciable base is
             * cost - salvage, so a salvage above the cost makes the base negative and
             * every monthly charge a credit to the expense account - an asset that
             * appreciates while being depreciated. Refused rather than clamped, because
             * clamping to the cost would silently change the user's figure.
             */
            $errors['salvage_value'] = 'A salvage value cannot be higher than the asset\'s cost, '
                .'because the amount available to depreciate is the cost less the salvage value.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @throws ValidationException
     */
    private function resolveCategory(Company $company, int $categoryId): FixedAssetCategory
    {
        $category = FixedAssetCategory::query()
            ->where('company_id', $company->getKey())
            ->whereKey($categoryId)
            ->first();

        if ($category === null) {
            throw ValidationException::withMessages([
                'fixed_asset_category_id' => 'The selected category does not belong to the active company.',
            ]);
        }

        return $category;
    }

    /**
     * The category re-read under a lock, and re-checked for still being active.
     *
     * The gap between resolveCategory() above and this is the whole reason the second
     * read exists: a category can be deactivated in between, and an asset created
     * against a retired category would carry a configuration the user can no longer
     * inspect from the category list.
     *
     * @throws ValidationException
     */
    private function lockCategory(FixedAssetCategory $category): FixedAssetCategory
    {
        $fresh = FixedAssetCategory::query()
            ->whereKey($category->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $fresh->is_active) {
            throw ValidationException::withMessages([
                'fixed_asset_category_id' => 'This category is inactive and cannot be used for a new asset.',
            ]);
        }

        return $fresh;
    }

    private function lock(FixedAsset $asset): FixedAsset
    {
        return FixedAsset::query()
            ->whereKey($asset->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
