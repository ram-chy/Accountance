<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Models\Supplier;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a supplier in the active company.
 *
 * The mirror of StoreCustomerRequest, including the deliberate absence of
 * company_id and is_active rules.
 */
class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Supplier::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->activeCompanyId();

        return [
            'supplier_code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('suppliers', 'supplier_code')->where('company_id', $companyId),
            ],

            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'tax_identifier' => ['nullable', 'string', 'max:100'],

            // Existence here; LIABILITY type and active status by the resolver.
            'payable_account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
        ];
    }

    protected function activeCompanyId(): int
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return $company->getKey();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_code.regex' => 'The supplier code may contain letters, numbers, dots, hyphens and '
                .'underscores, and must start with a letter or number.',
            'supplier_code.unique' => 'This supplier code is already in use for the selected company.',
        ];
    }
}
