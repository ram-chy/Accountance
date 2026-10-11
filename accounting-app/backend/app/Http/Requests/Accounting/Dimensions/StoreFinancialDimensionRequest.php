<?php

namespace App\Http\Requests\Accounting\Dimensions;

use App\Enums\FinancialDimensionType;
use App\Models\FinancialDimension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a financial dimension.
 *
 * The type is a controlled vocabulary rather than free text because it is the
 * axis a report is grouped by: an unknown type would be a dimension no report can
 * name. Company and active flag are absent by design - the company comes from the
 * context and a dimension is created active, so neither is a field this request
 * could be asked to trust.
 */
class StoreFinancialDimensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FinancialDimension::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(FinancialDimensionType::values())],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'The dimension type must be one of: '.implode(', ', FinancialDimensionType::values()).'.',
        ];
    }
}
