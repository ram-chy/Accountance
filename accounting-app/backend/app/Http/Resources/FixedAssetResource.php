<?php

namespace App\Http\Resources;

use App\Models\FixedAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fixed asset, with its derived figures.
 *
 * WHAT IS STORED AND WHAT IS DERIVED
 *
 * The columns are emitted as they are stored. The accumulated depreciation, the
 * carrying value and the remaining period count are NOT stored on the asset - the
 * schema deliberately carries no balance column - so they are computed here from the
 * posted depreciation rows, which are the register's only record of what has been
 * written off.
 *
 * Those three are emitted because an asset list that only showed original cost would
 * make the reader do the one thing this module exists to do correctly. They are
 * computed with the same Money arithmetic the services use, so the number in the
 * response and the number the next depreciation charge is based on come from one
 * implementation rather than two.
 *
 * When the controller eager-loads `depreciations`, the accumulated figure is summed
 * from the loaded rows and no query is issued per asset; without it, the model's
 * accessor falls back to the database. Either way the arithmetic is exact - the
 * fallback sums in DECIMAL and the loaded path reduces with Money rather than with
 * floating point, because a register of a thousand assets summed as floats would
 * drift.
 *
 * `depreciations` and `disposal` are emitted only when loaded. The schedule endpoint
 * loads the former; show and the register load whichever the payload needs.
 *
 * @mixin FixedAsset
 */
class FixedAssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_number' => $this->asset_number,
            'name' => $this->name,
            'description' => $this->description,
            'serial_number' => $this->serial_number,
            'supplier_reference' => $this->supplier_reference,

            'status' => $this->status->value,
            'acquisition_method' => $this->acquisition_method->value,

            'fixed_asset_category_id' => $this->fixed_asset_category_id,
            'category' => new FixedAssetCategoryResource($this->whenLoaded('category')),

            'acquisition_date' => $this->acquisition_date->toDateString(),
            'depreciation_start_date' => $this->depreciation_start_date->toDateString(),

            // Raw DECIMAL(20,4) strings, as every other monetary field in this API.
            'original_cost' => $this->original_cost,
            'salvage_value' => $this->salvage_value,

            'useful_life_months' => $this->useful_life_months,
            'depreciation_method' => $this->depreciation_method->value,

            /*
             * The derived figures, described above. `carrying_value` is clamped to
             * the salvage value by the model, so it never reports an asset as worth
             * less than its residual.
             */
            'accumulated_depreciation' => $this->accumulatedDepreciationAmount()->toDatabase(),
            'carrying_value' => $this->carryingAmount()->toDatabase(),
            'monthly_charge' => $this->monthlyChargeAmount()->toDatabase(),
            'period_count' => $this->periodCount(),
            'remaining_period_count' => $this->remainingPeriodCount(),

            'asset_account_id' => $this->asset_account_id,
            'accumulated_depreciation_account_id' => $this->accumulated_depreciation_account_id,
            'depreciation_expense_account_id' => $this->depreciation_expense_account_id,
            'gain_on_disposal_account_id' => $this->gain_on_disposal_account_id,
            'loss_on_disposal_account_id' => $this->loss_on_disposal_account_id,
            'acquisition_account_id' => $this->acquisition_account_id,

            'asset_account' => new AccountResource($this->whenLoaded('assetAccount')),
            'accumulated_depreciation_account' => new AccountResource($this->whenLoaded('accumulatedDepreciationAccount')),
            'depreciation_expense_account' => new AccountResource($this->whenLoaded('depreciationExpenseAccount')),
            'acquisition_account' => new AccountResource($this->whenLoaded('acquisitionAccount')),

            'journal_id' => $this->journal_id,
            'journal' => new JournalResource($this->whenLoaded('journal')),
            'capitalised_at' => $this->capitalised_at?->toIso8601String(),
            'fully_depreciated_at' => $this->fully_depreciated_at?->toIso8601String(),
            'disposed_at' => $this->disposed_at?->toIso8601String(),

            'depreciations' => FixedAssetDepreciationResource::collection($this->whenLoaded('depreciations')),
            'disposal' => new FixedAssetDisposalResource($this->whenLoaded('disposal')),

            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
