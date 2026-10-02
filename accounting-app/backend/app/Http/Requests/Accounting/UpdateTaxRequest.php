<?php

namespace App\Http\Requests\Accounting;

use App\Enums\TaxCalculationBasis;
use App\Enums\TaxType;
use App\Models\Tax;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a tax's configuration.
 *
 * PUT semantics, so every field except code and name is optional and an absent
 * field means "leave it alone" rather than "clear it". Only description can be
 * cleared by omission-safe means, because a client that wants it gone sends null.
 *
 * Authorises with the policy rather than a raw permission string; see
 * StoreAccountRequest for why.
 */
class UpdateTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tax'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        /** @var Tax $tax */
        $tax = $this->route('tax');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9._\- ]+$/',
                /*
                 * Ignores the tax being updated. Without whereKeyNot this would
                 * reject a request that changed nothing, because the row would be
                 * found colliding with itself.
                 */
                Rule::unique('taxes', 'code')
                    ->where('company_id', $company->getKey())
                    ->ignore($tax->getKey()),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tax_type' => ['sometimes', 'required', 'string', Rule::in(TaxType::values())],
            'calculation_basis' => ['sometimes', 'required', 'string', Rule::in(TaxCalculationBasis::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The tax code may contain letters, numbers, dots, dashes and spaces only.',
            'tax_type.in' => 'The tax type must be one of OUTPUT, INPUT or BOTH.',
            'calculation_basis.in' => 'The calculation basis must be EXCLUSIVE or INCLUSIVE.',
        ];
    }
}
