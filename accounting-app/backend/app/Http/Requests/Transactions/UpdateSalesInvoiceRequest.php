<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a draft sales invoice.
 *
 * Every field is `sometimes`d except the lines, which are required when present.
 * Two consequences worth stating:
 *
 *  - A partial update is a partial update. `sometimes` means an absent key is
 *    simply not written, so a client fixing one typo does not have to resend the
 *    whole invoice and cannot accidentally blank a field by omitting it.
 *
 *  - `status` is not among the rules. A client cannot move an invoice to POSTED
 *    through this endpoint, and the only way to post is the posting service,
 *    which is the only thing that can create the journal with it.
 */
class UpdateSalesInvoiceRequest extends FormRequest
{
    use ValidatesTransactionAmounts;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('invoice'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            'invoice_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'tax_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            /*
             * Lines are all-or-nothing when present: there is no way to add one
             * line without restating the others, and a payload that tried would
             * either lose lines or duplicate them. DocumentCalculator recomputes
             * every total from whatever arrives.
             */
            'lines' => ['sometimes', 'required', 'array', 'min:1', 'max:'.config('accounting.limits.max_lines_per_journal', 500)],
            'lines.*' => ['required', 'array'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.quantity' => $this->positiveMoneyRule(),
            'lines.*.unit_price' => $this->decimalAmountRule(),
            'lines.*.discount' => $this->nonNegativeMoneyRule(),
            'lines.*.tax_rate' => $this->nonNegativeMoneyRule(),
            'lines.*.revenue_account_id' => $this->companyAccountRule(),
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
            'due_date.after_or_equal' => 'The due date cannot be earlier than the invoice date.',
            'lines.*.quantity.gt' => 'A line quantity must be greater than zero.',
        ];
    }
}
