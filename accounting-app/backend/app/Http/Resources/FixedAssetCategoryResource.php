<?php

namespace App\Http\Resources;

use App\Models\FixedAssetCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fixed asset category - the template a new asset copies from.
 *
 * The five accounts are emitted as bare ids AND, when loaded, as nested account
 * objects. The ids are what a client round-trips on an edit; the objects are what
 * lets a category list render "Motor Vehicles -> 1500 Vehicles / 1590 Accumulated
 * Depreciation" without a second request per row. Both are present rather than only
 * the objects because the create and update payloads speak in ids, and a client
 * that had to reach into `asset_account.id` to build the next request would be
 * coupled to the nesting.
 *
 * `assets_count` is emitted only when the controller has counted it (`withCount`),
 * so the list can show how much history a category carries - which is the fact that
 * decides whether it may be deleted - without the resource issuing a query per row.
 *
 * @mixin FixedAssetCategory
 */
class FixedAssetCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,

            'useful_life_months' => $this->useful_life_months,
            'depreciation_method' => $this->depreciation_method->value,
            'is_active' => $this->is_active,

            'asset_account_id' => $this->asset_account_id,
            'accumulated_depreciation_account_id' => $this->accumulated_depreciation_account_id,
            'depreciation_expense_account_id' => $this->depreciation_expense_account_id,
            'gain_on_disposal_account_id' => $this->gain_on_disposal_account_id,
            'loss_on_disposal_account_id' => $this->loss_on_disposal_account_id,

            'asset_account' => new AccountResource($this->whenLoaded('assetAccount')),
            'accumulated_depreciation_account' => new AccountResource($this->whenLoaded('accumulatedDepreciationAccount')),
            'depreciation_expense_account' => new AccountResource($this->whenLoaded('depreciationExpenseAccount')),
            'gain_on_disposal_account' => new AccountResource($this->whenLoaded('gainOnDisposalAccount')),
            'loss_on_disposal_account' => new AccountResource($this->whenLoaded('lossOnDisposalAccount')),

            'assets_count' => $this->whenCounted('assets'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
