<?php

namespace App\Http\Requests\Accounting;

use App\Enums\TaxCalculationBasis;
use App\Enums\TaxType;
use App\Models\Company;
use App\Models\Tax;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a tax in the active company.
 *
 * Authorises with `can('create', Tax::class)` rather than a raw permission
 * string, for the reason documented in StoreAccountRequest: a raw string is
 * checked by Spatie directly and never reaches TaxPolicy, which is the class of
 * bug that let Phase 3 ship an IDOR.
 *
 * No `company_id` is accepted. The tenant comes from CompanyContext and from
 * nowhere else, so there is no field for a client to tamper with.
 */
class StoreTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Tax::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return [
            /*
             * Uniqueness is per company. The rule gives an immediate field-level
             * error; the unique index on (company_id, code) is what actually holds
             * when two requests race.
             */
            'code' => [
                'required',
                'string',
                'max:50',
                // Trimmed but not coerced to a number: a tax code is an identifier,
                // and a company may reasonably use "VAT", "GST-10" or "VAT.2".
                'regex:/^[A-Za-z0-9._\- ]+$/',
                Rule::unique('taxes', 'code')->where('company_id', $company->getKey()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],

            'tax_type' => ['required', 'string', Rule::in(TaxType::values())],
            'calculation_basis' => ['required', 'string', Rule::in(TaxCalculationBasis::values())],
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
            'tax_type.required' => 'A tax type is required, so the engine knows which side of a transaction this tax belongs to.',
        ];
    }
}
