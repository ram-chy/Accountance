<?php

namespace App\Http\Requests\Accounting\FixedAssets;

use App\Enums\DepreciationMethod;
use App\Models\FixedAssetCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a fixed asset category.
 *
 * The account fields are validated for EXISTENCE IN THE ACTIVE COMPANY here and for
 * their ELIGIBILITY as accounts by FixedAssetCategoryService, which re-applies the
 * type and normal-balance rules at write time. That split is the same one every
 * transaction request in this application uses: a rule that needs only the payload
 * and the accounts table belongs in the request, and a rule that encodes what the
 * account is FOR belongs next to the code that will keep using it.
 *
 * `is_active` is not a rule. A category is created active; retiring it is a separate
 * operation with its own permission and its own guard against leaving assets behind.
 */
class StoreFixedAssetCategoryRequest extends FormRequest
{
    use ValidatesFixedAssetInputs;

    public function authorize(): bool
    {
        return $this->user()->can('create', FixedAssetCategory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],

            /*
             * A month count, not a date. Bounded by the unsigned smallint column and
             * floored at one because a category with no life cannot depreciate
             * anything - the calculator divides by this number.
             */
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:65535'],
            'depreciation_method' => ['sometimes', 'string', Rule::in(DepreciationMethod::values())],

            'asset_account_id' => $this->companyAccountRule(),
            'accumulated_depreciation_account_id' => $this->companyAccountRule(),
            'depreciation_expense_account_id' => $this->companyAccountRule(),

            // Optional: a company may configure only the direction it expects.
            'gain_on_disposal_account_id' => $this->nullableCompanyAccountRule(),
            'loss_on_disposal_account_id' => $this->nullableCompanyAccountRule(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'asset_account_id.exists' => 'The asset account does not exist in the active company.',
            'accumulated_depreciation_account_id.exists' => 'The accumulated depreciation account does not exist in the active company.',
            'depreciation_expense_account_id.exists' => 'The depreciation expense account does not exist in the active company.',
            'gain_on_disposal_account_id.exists' => 'The gain on disposal account does not exist in the active company.',
            'loss_on_disposal_account_id.exists' => 'The loss on disposal account does not exist in the active company.',
            'useful_life_months.min' => 'A category must have a useful life of at least one month.',
        ];
    }
}
