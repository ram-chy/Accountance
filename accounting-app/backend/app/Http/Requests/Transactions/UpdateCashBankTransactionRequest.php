<?php

namespace App\Http\Requests\Transactions;

use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a draft cash/bank transaction.
 *
 * `transaction_type` is absent from the rules, and not merely validated-and-
 * ignored: a deposit that becomes a transfer rewrites which accounts are
 * acceptable and is in substance a different document. The type of an existing
 * transaction is therefore fixed, and the service reads it from the stored row.
 *
 * Every field is `sometimes` because this is a partial update. The account pair
 * is re-validated as a whole by the service - merging the incoming fields with
 * the stored ones and resolving both - because a partial edit can create a
 * collision that neither the stored document nor the submitted payload had on
 * its own: changing only the destination to the current source.
 */
class UpdateCashBankTransactionRequest extends FormRequest
{
    use ValidatesTransactionAmounts;

    public function authorize(): bool
    {
        /** @var CashBankTransaction $transaction */
        $transaction = $this->route('transaction');

        return $this->user()->can('update', $transaction);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->activeCompanyId();

        $accountRule = fn () => [
            'sometimes',
            'required',
            'integer',
            Rule::exists('accounts', 'id')->where('company_id', $companyId),
        ];

        return [
            'transaction_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'amount' => array_merge(['sometimes'], $this->positiveMoneyRule()),

            'source_account_id' => $accountRule(),
            'destination_account_id' => $accountRule(),

            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * The same-account check as the store request, but only meaningful when both
     * ids arrive together. When just one arrives the service compares it against
     * the stored other side, which is the only place both values are known.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $source = $this->input('source_account_id');
            $destination = $this->input('destination_account_id');

            if (! is_numeric($source) || ! is_numeric($destination)) {
                return;
            }

            if ((int) $source === (int) $destination) {
                $validator->errors()->add(
                    'destination_account_id',
                    'The source and destination accounts must be different.'
                );
            }
        });
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
            'transaction_date.date_format' => 'The transaction date must be in YYYY-MM-DD format.',
        ];
    }
}
