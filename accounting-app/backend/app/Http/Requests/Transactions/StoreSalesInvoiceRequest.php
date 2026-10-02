<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a draft sales invoice in the active company.
 *
 * The rule that shapes this class is what the payload may NOT contain. There is
 * no `subtotal`, `tax_total`, `grand_total`, `paid_total`, `balance_due`,
 * `journal_id`, `company_id`, `status`, `created_by`, `posted_by` or
 * `posted_at` rule anywhere below, because the request does not validate those
 * parameters at all - a client that sends them has them ignored rather than
 * trusted. That is stronger than rejecting them: there is no version of this
 * request in which a client-supplied total reaches the database.
 *
 * The invoice number is likewise absent. It is allocated by the server, and a
 * rule that merely ignored it would leave a client believing its own numbering
 * was in effect.
 */
class StoreSalesInvoiceRequest extends FormRequest
{
    use ValidatesTransactionAmounts;

    public function authorize(): bool
    {
        return $this->user()->can('create', SalesInvoice::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            /*
             * No `after:today` rule, matching the journal request: backdated
             * documents are normal bookkeeping, and the accounting period check
             * at posting time is the rule that actually decides whether a date is
             * usable. Rejecting past dates here would block legitimate history
             * entry without protecting any invariant.
             */
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],

            'notes' => ['nullable', 'string', 'max:5000'],

            /*
             * Optional here even when a line carries a tax rate. Whether a
             * taxable invoice is missing its tax account is a whole-document
             * property that depends on the computed tax total, so the service
             * reports it - with a message that explains why - rather than a
             * conditional rule firing before the totals are known.
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
            'lines.*.unit_price' => $this->decimalAmountRule(),
            'lines.*.discount' => $this->nonNegativeMoneyRule(),
            'lines.*.tax_rate' => $this->nonNegativeMoneyRule(),

            /*
             * Phase 10. A line may name configured taxes instead of a percentage.
             *
             * Existence is deliberately not checked with exists(). The ids are
             * resolved company-scoped by TaxRuleResolver, which reports a
             * cross-company id as a validation error naming it - the same answer
             * a client gets for an id that does not exist, so a request cannot be
             * used to discover another tenant's tax ids. An exists() rule here
             * would answer only "no such row" without that property.
             *
             * A line may send both tax_rate and tax_ids. The ids win: a line that
             * names a tax must be charged that tax's rate on the document's date,
             * not a percentage typed alongside it. DocumentCalculator is where
             * that precedence is applied.
             */
            'lines.*.tax_ids' => ['sometimes', 'array'],
            'lines.*.tax_ids.*' => ['integer'],

            /*
             * Existence is checked here; appropriateness (it must be a REVENUE
             * account, and active) is checked by TransactionAccountResolver,
             * which is also what runs at posting time. Splitting it this way
             * means the type rule exists once rather than per endpoint.
             */
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
