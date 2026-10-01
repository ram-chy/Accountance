<?php

namespace App\Http\Requests\Transactions;

use App\Enums\CashBankTransactionType;
use App\Enums\PaymentStatus;
use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter the cash/bank transaction listing.
 *
 * Exists so the controller does not hand-roll query-string parsing, and so the
 * accepted filters are declared in one place instead of being implied by a chain
 * of when() calls. Every filter is optional and independent.
 *
 * Date filters address transaction_date - the date the money moved, and the date
 * given to the resulting journal - never created_at. A transaction entered today
 * for last month belongs in last month's report, and filtering on when it was
 * typed would file it under the wrong period.
 */
class CashBankTransactionFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', CashBankTransaction::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'transaction_type' => ['nullable', 'string', Rule::in(CashBankTransactionType::values())],
            'status' => ['nullable', 'string', Rule::in(PaymentStatus::values())],

            /*
             * Matches EITHER side. "Show me everything that touched this bank
             * account" is the question a user actually asks, and making them
             * choose between source and destination to phrase it would mean two
             * requests and an awkward merge for one answer. Company-scoped, so a
             * filter cannot be used to probe for another tenant's account ids.
             */
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $this->activeCompanyId()),
            ],

            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],

            'reference' => ['nullable', 'string', 'max:255'],

            /*
             * Capped at 100 because the endpoint is a listing, not an export. An
             * unbounded per_page is a way to ask the server to hold an entire
             * company's transaction history in memory, and the brief rules out
             * CSV export for this phase in any case.
             */
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'transaction_type.in' => 'The transaction type must be DEPOSIT, WITHDRAWAL or TRANSFER.',
            'account_id.exists' => 'The selected account does not exist in the active company.',
        ];
    }

    protected function activeCompanyId(): int
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return $company->getKey();
    }
}
