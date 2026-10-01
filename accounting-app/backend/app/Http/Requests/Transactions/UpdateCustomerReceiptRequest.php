<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a draft customer receipt.
 *
 * `amount` and `allocations` are independent here, so reducing the amount without
 * resending allocations has to be caught. The service re-checks the existing
 * allocation total against the new amount; the request only handles the case
 * where both are present.
 */
class UpdateCustomerReceiptRequest extends FormRequest
{
    use ValidatesTransactionAmounts;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('receipt'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->activeCompanyId();

        return [
            'customer_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId),
            ],

            'receipt_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'amount' => array_merge(['sometimes'], $this->positiveMoneyRule()),

            'payment_account_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],

            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'allocations' => ['sometimes', 'required', 'array', 'min:1'],
            'allocations.*' => ['required', 'array'],
            'allocations.*.sales_invoice_id' => [
                'required',
                'integer',
                Rule::exists('sales_invoices', 'id')->where('company_id', $companyId),
            ],
            'allocations.*.amount' => $this->positiveMoneyRule(),
        ];
    }

    protected function activeCompanyId(): int
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return $company->getKey();
    }
}
