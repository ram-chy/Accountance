<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Models\Supplier;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a supplier.
 *
 * The mirror of UpdateCustomerRequest, including the ignore() that lets a
 * supplier keep its own code.
 */
class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('supplier'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Supplier $supplier */
        $supplier = $this->route('supplier');

        return [
            'supplier_code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('suppliers', 'supplier_code')
                    ->where('company_id', $this->activeCompanyId())
                    ->ignore($supplier->getKey()),
            ],

            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:100'],
            'payable_account_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $this->activeCompanyId()),
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
