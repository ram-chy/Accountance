<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Models\Customer;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a customer in the active company.
 *
 * No company_id rule, no is_active rule. The first is server-owned from the
 * request context; the second is not something a create payload decides - a
 * customer created is a customer that is active, and deactivation is a separate
 * authorised action on its own endpoint.
 */
class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Customer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->activeCompanyId();

        return [
            /*
             * Unique per company, checked here for a fast, field-specific error
             * and again by the UNIQUE index. The index is the real guarantee -
             * two concurrent creates can both pass this rule, and only the index
             * can refuse the second one.
             */
            'customer_code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('customers', 'customer_code')->where('company_id', $companyId),
            ],

            'name' => ['required', 'string', 'max:255'],

            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],

            /*
             * Two-letter ISO code, uppercased rather than lowercased: a
             * lowercase "us" would be a different string in the database from
             * "US", and nothing would ever compare them.
             */
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],

            'tax_identifier' => ['nullable', 'string', 'max:100'],

            /*
             * Existence within this company only. Whether the account is an
             * ASSET and whether it is active are decided by
             * TransactionAccountResolver, which also runs at posting time, so
             * the type rule is written once.
             */
            'receivable_account_id' => [
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
            'customer_code.regex' => 'The customer code may contain letters, numbers, dots, hyphens and '
                .'underscores, and must start with a letter or number.',
            'customer_code.unique' => 'This customer code is already in use for the selected company.',
        ];
    }
}
