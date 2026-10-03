<?php

namespace App\Http\Requests\Accounting\FixedAssets;

use App\Enums\FixedAssetAcquisitionMethod;
use App\Enums\FixedAssetStatus;
use App\Models\FixedAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter the fixed asset listing.
 *
 * Every filter is optional and independent, and they are declared here rather than
 * implied by a chain of when() calls in the controller - so the accepted vocabulary
 * of the endpoint is in one readable place.
 *
 * The date filters address `acquisition_date`, never `created_at`. An asset bought
 * last year and entered today belongs in last year's register, and filtering on when
 * the row was typed would file it under the wrong period - the same distinction the
 * cash/bank filter draws.
 *
 * `per_page` is capped at 100 because this is a listing and not an export.
 */
class FixedAssetFilterRequest extends FormRequest
{
    use ValidatesFixedAssetInputs;

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', FixedAsset::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(FixedAssetStatus::values())],
            'acquisition_method' => ['nullable', 'string', Rule::in(FixedAssetAcquisitionMethod::values())],

            // Company-scoped, so a filter cannot be used to probe for another
            // tenant's category ids.
            'fixed_asset_category_id' => [
                'nullable',
                'integer',
                Rule::exists('fixed_asset_categories', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],

            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fixed_asset_category_id.exists' => 'The selected category does not exist in the active company.',
        ];
    }
}
