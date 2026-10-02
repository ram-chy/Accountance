<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Models\PurchaseBill;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a draft purchase bill in the active company.
 *
 * The mirror of StoreSalesInvoiceRequest, differing only in the price field
 * (unit_cost) and the per-line account (expense_account_id). As with invoices,
 * there is no rule for any server-computed or server-owned field, so there is no
 * payload through which a client can set a total, a number or a status.
 */
class StorePurchaseBillRequest extends FormRequest
{
    use ValidatesTransactionAmounts;

    public function authorize(): bool
    {
        return $this->user()->can('create', PurchaseBill::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            'bill_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:bill_date'],

            'notes' => ['nullable', 'string', 'max:5000'],

            /*
             * Input tax. Existence is checked here; that it must be an ASSET is
             * decided by TransactionAccountResolver. It is a different account
             * type from an invoice's tax account for the reason the migration
             * comment on purchase_bills.tax_account_id spells out: recovered tax
             * is money the authority owes back.
             */
            'tax_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            'lines' => ['required', 'array', 'min:1', 'max:'.config('accounting.limits.max_lines_per_journal', 500)],
            'lines.*' => ['required', 'array'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.quantity' => $this->positiveMoneyRule(),
            'lines.*.unit_cost' => $this->decimalAmountRule(),
            'lines.*.discount' => $this->nonNegativeMoneyRule(),
            'lines.*.tax_rate' => $this->nonNegativeMoneyRule(),

            /*
             * Phase 10. See the matching note in StoreSalesInvoiceRequest: ids are
             * resolved company-scoped by TaxRuleResolver rather than checked here,
             * and they take precedence over a tax_rate sent on the same line.
             */
            'lines.*.tax_ids' => ['sometimes', 'array'],
            'lines.*.tax_ids.*' => ['integer'],
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
