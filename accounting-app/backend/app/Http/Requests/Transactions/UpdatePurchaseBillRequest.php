<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a draft purchase bill.
 *
 * The mirror of UpdateSalesInvoiceRequest, including the absence of a status rule
 * so a bill cannot be posted through an edit.
 */
class UpdatePurchaseBillRequest extends FormRequest
{
    use ValidatesTransactionAmounts;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('bill'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            'bill_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:bill_date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'tax_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            'lines' => ['sometimes', 'required', 'array', 'min:1', 'max:'.config('accounting.limits.max_lines_per_journal', 500)],
            'lines.*' => ['required', 'array'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.quantity' => $this->positiveMoneyRule(),
            'lines.*.unit_cost' => $this->decimalAmountRule(),
            'lines.*.discount' => $this->nonNegativeMoneyRule(),
            'lines.*.tax_rate' => $this->nonNegativeMoneyRule(),
            'lines.*.expense_account_id' => $this->companyAccountRule(),
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
            'due_date.after_or_equal' => 'The due date cannot be earlier than the bill date.',
            'lines.*.quantity.gt' => 'A line quantity must be greater than zero.',
        ];
    }
}
