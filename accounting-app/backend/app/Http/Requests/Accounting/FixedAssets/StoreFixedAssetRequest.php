<?php

namespace App\Http\Requests\Accounting\FixedAssets;

use App\Models\FixedAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a draft fixed asset.
 *
 * `acquisition_method` is deliberately absent, exactly as `transaction_type` is
 * absent from StoreCashBankTransactionRequest. The method is a property of the
 * endpoint called - there are separate cash and supplier-credit routes - and the
 * service takes it as an argument the controller supplies from the route. Letting the
 * body name it would mean validating "is this account a cash/bank account or a
 * payable" against a value the client chose, which is the one thing that rule must
 * never be pointed at.
 *
 * `acquisition_account_id` IS in the payload, because which bank account paid for
 * which van is a fact about this purchase rather than a category-wide policy. It is
 * validated for company membership here and for the eligibility the route's method
 * demands in the service, where a CASH route asking for a payable is refused.
 *
 * The five category-sourced accounts are not in the payload at all. They are copied
 * from the category onto the asset at creation, so no amount of client input can
 * give an asset an accumulated-depreciation account its category does not name.
 */
class StoreFixedAssetRequest extends FormRequest
{
    use ValidatesFixedAssetInputs;

    public function authorize(): bool
    {
        return $this->user()->can('create', FixedAsset::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fixed_asset_category_id' => [
                'required',
                'integer',
                Rule::exists('fixed_asset_categories', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],

            'acquisition_date' => ['required', 'date_format:Y-m-d'],
            'depreciation_start_date' => ['required', 'date_format:Y-m-d'],

            'original_cost' => $this->positiveMoneyRule(),
            'salvage_value' => $this->nonNegativeMoneyRule(),

            'acquisition_account_id' => $this->companyAccountRule(),
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
