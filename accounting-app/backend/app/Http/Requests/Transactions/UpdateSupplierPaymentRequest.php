<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a draft supplier payment.
 *
 * The mirror of UpdateCustomerReceiptRequest. A reduction in amount without new
 * allocations is caught by the service against the existing allocation total.
 */
class UpdateSupplierPaymentRequest extends FormRequest
{
    use ValidatesTransactionAmounts;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('payment'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->activeCompanyId();

        return [
            'supplier_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId),
            ],

            'payment_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
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
            'allocations.*.purchase_bill_id' => [
                'required',
                'integer',
                Rule::exists('purchase_bills', 'id')->where('company_id', $companyId),
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
