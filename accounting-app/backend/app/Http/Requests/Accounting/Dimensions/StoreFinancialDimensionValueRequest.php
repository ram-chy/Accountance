<?php

namespace App\Http\Requests\Accounting\Dimensions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a value under a financial dimension.
 *
 * Authorized against the parent dimension rather than a value policy: a value has
 * no independent existence, every route addresses it through its dimension, and
 * the capability is the one that decides what may be done to that dimension.
 * Company scoping is inherited - the dimension was resolved by a binding scoped to
 * the active company, and the value's own company is its parent's.
 */
class StoreFinancialDimensionValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', $this->route('dimension'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
        ];
    }
}
