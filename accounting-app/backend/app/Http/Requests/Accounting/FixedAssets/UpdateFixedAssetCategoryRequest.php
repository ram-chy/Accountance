<?php

namespace App\Http\Requests\Accounting\FixedAssets;

use App\Enums\DepreciationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a fixed asset category.
 *
 * Every field is `sometimes`, so an absent key is not written and a client fixing a
 * typo does not have to resend the account configuration. The subtlety is that the
 * gain and loss accounts are genuinely nullable, so there are two ways to leave one
 * alone and one way to clear it:
 *
 *   omit the key            leave the stored value
 *   send it as null         clear it
 *   send an id              point it at that account
 *
 * `sometimes` plus `nullable` is what distinguishes the first from the second, and
 * FixedAssetCategoryService reads `array_key_exists` rather than `??` for exactly
 * this reason - so that an explicit null is not mistaken for an omission and
 * silently ignored.
 *
 * Whether the two accounts may BOTH be absent is not decided here. The service
 * merges the request onto the stored row and validates the effective configuration,
 * because "at least one disposal account" is a rule about the result rather than
 * about any single payload.
 */
class UpdateFixedAssetCategoryRequest extends FormRequest
{
    use ValidatesFixedAssetInputs;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('fixedAssetCategory'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'useful_life_months' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'depreciation_method' => ['sometimes', 'string', Rule::in(DepreciationMethod::values())],

            'asset_account_id' => $this->sometimesCompanyAccountRule(),
            'accumulated_depreciation_account_id' => $this->sometimesCompanyAccountRule(),
            'depreciation_expense_account_id' => $this->sometimesCompanyAccountRule(),

            /*
             * The nullable variants carry no `sometimes` and do not need one: `nullable`
             * is not an implicit rule, so an absent key skips validation entirely, while
             * an explicit null passes through and clears the stored value. That is
             * exactly the distinction the category service reads for with
             * array_key_exists().
             */
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
        ];
    }
}
