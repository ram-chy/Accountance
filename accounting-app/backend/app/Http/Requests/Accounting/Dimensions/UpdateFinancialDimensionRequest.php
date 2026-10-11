<?php

namespace App\Http\Requests\Accounting\Dimensions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Update a financial dimension.
 *
 * Only code and name, both `sometimes`, so a client fixing a typo does not have
 * to resend the whole record. The type is absent on purpose rather than by
 * oversight: it is the dimension's identity as far as every report is concerned,
 * and moving a dimension from COST_CENTER to DEPARTMENT would silently
 * re-categorise every assignment ever made to it.
 *
 * `is_active` is absent for the same reason it is absent on create - retiring a
 * dimension is its own endpoint with its own audit row, not a field a PUT can
 * change as a side effect.
 */
class UpdateFinancialDimensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('dimension'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
        ];
    }
}
