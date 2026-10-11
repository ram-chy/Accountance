<?php

namespace App\Http\Requests\Accounting\Dimensions;

use App\Enums\FinancialDimensionType;
use App\Models\FinancialDimension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter the financial dimension listing.
 *
 * `type` is the one dimension-specific filter: a company typically runs several
 * dimensions at once and "show me the cost centres" is the ordinary question.
 * `is_active` is three-state - absent for both, true for usable, false for
 * retired - so that a retired dimension can still be found without a boolean whose
 * absence silently means false.
 */
class FinancialDimensionFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', FinancialDimension::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(FinancialDimensionType::values())],
            'is_active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
