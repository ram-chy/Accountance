<?php

namespace App\Http\Requests\Accounting\Tax;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Move a rate's period or change its percentage.
 *
 * Same reasoning as StoreTaxRateRequest: overlap is the service's rule, this
 * request checks the shape.
 */
class UpdateTaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateRate', $this->route('rate'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99.9999'],
            'effective_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rate.numeric' => 'The tax rate must be a number of percent, for example 7.5 for 7.5%.',
            'rate.max' => 'The tax rate must be less than 100%.',
            'rate.min' => 'The tax rate cannot be negative.',
        ];
    }
}
