<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Models\Customer;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a customer.
 *
 * customer_code is `sometimes`d and excluded from the unique check against
 * itself, so resubmitting the same code is not a conflict. The ignore() is
 * essential rather than cosmetic: without it every update would collide with the
 * row being updated and no customer could ever be renamed.
 */
class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('customer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return [
            'customer_code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('customers', 'customer_code')
                    ->where('company_id', $this->activeCompanyId())
                    ->ignore($customer->getKey()),
            ],

            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:100'],
            'receivable_account_id' => [
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
            'customer_code.regex' => 'The customer code may contain letters, numbers, dots, hyphens and '
                .'underscores, and must start with a letter or number.',
            'customer_code.unique' => 'This customer code is already in use for the selected company.',
        ];
    }
}
