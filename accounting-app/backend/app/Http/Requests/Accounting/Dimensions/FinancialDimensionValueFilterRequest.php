<?php

namespace App\Http\Requests\Accounting\Dimensions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filter the values of one dimension.
 *
 * The same three-state `is_active` as the dimension listing: a retired value has
 * to remain visible so that a historical assignment can still be read, while a
 * picker wants only the usable ones.
 */
class FinancialDimensionValueFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('dimension'));
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
