<?php

namespace App\Http\Requests\Accounting\FixedAssets;

use App\Models\FixedAssetCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filter the fixed asset category listing.
 *
 * Three filters and no date range: a category has no transaction date, so filtering
 * by one would be filtering by a fact the row does not carry. `is_active` is a
 * three-state filter - absent for all, true for usable, false for retired - which is
 * why it is `nullable` boolean rather than a flag whose absence means false.
 */
class FixedAssetCategoryFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', FixedAssetCategory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
