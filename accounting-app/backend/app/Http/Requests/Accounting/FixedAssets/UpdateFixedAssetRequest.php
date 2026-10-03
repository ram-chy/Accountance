<?php

namespace App\Http\Requests\Accounting\FixedAssets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a draft fixed asset.
 *
 * Every field is optional, and the service refuses the whole request unless the
 * asset is still a draft. That refusal, not the field list here, is what protects a
 * capitalised asset: once the cost is in the ledger its acquisition method, its
 * accounts and - for practical purposes - its cost are part of a posted journal, and
 * FixedAssetService re-checks the status under a row lock before writing anything.
 *
 * `acquisition_method` is still absent, for the reason StoreFixedAssetRequest gives:
 * it is never a payload field. So is the five-account snapshot - it is re-copied
 * wholesale if the category changes, and otherwise left alone.
 *
 * The money fields use `sometimes`: an omitted cost is left as stored, while a
 * supplied one must be a positive decimal string.
 */
class UpdateFixedAssetRequest extends FormRequest
{
    use ValidatesFixedAssetInputs;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('fixedAsset'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fixed_asset_category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('fixed_asset_categories', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'supplier_reference' => ['sometimes', 'nullable', 'string', 'max:100'],

            'acquisition_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'depreciation_start_date' => ['sometimes', 'required', 'date_format:Y-m-d'],

            'original_cost' => array_merge(['sometimes'], $this->positiveMoneyRule()),
            'salvage_value' => array_merge(['sometimes'], $this->nonNegativeMoneyRule()),

            'acquisition_account_id' => $this->sometimesCompanyAccountRule(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fixed_asset_category_id.exists' => 'The selected category does not exist in the active company.',
            'acquisition_account_id.exists' => 'The acquisition account does not exist in the active company.',
            'original_cost.gt' => 'The original cost must be greater than zero.',
            'salvage_value.min' => 'The salvage value cannot be negative.',
        ];
    }
}
