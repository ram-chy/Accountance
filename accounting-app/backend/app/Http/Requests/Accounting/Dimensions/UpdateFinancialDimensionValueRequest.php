<?php

namespace App\Http\Requests\Accounting\Dimensions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Update a financial dimension value.
 *
 * Both fields `sometimes` for the same reason as the dimension update: a typo in
 * a value's name is fixed by sending the name, not the whole record. The parent
 * dimension is not editable here - a value never moves between dimensions, because
 * every assignment made to it would then mean something different.
 */
class UpdateFinancialDimensionValueRequest extends FormRequest
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
