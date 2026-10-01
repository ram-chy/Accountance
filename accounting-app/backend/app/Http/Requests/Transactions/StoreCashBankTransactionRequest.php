<?php

namespace App\Http\Requests\Transactions;

use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a draft cash/bank transaction of any type.
 *
 * One class rather than three, for a reason that has nothing to do with brevity:
 * the field set is genuinely identical for a deposit, a withdrawal and a
 * transfer. Both account ids are required, the date and amount are the same, and
 * the difference between the three is entirely in the business rule about which
 * side has to be cash/bank - and that rule is not expressible as a validation
 * rule, because it depends on the transaction type and lives in
 * CashBankTransactionService alongside the code that will enforce it at posting
 * time too.
 *
 * Three classes differing only in which side they mark as required would have
 * been three places to keep that rule in step, and the one that drifted would be
 * the one nobody tested.
 *
 * `transaction_type` is deliberately absent from the rules. It is not validated-
 * and-ignored; there is no payload through which a client can set it. The type
 * comes from the endpoint called.
 *
 * Worth being precise about why, because the obvious reason is not quite right.
 * The posting direction is not what would break: every type posts Dr destination
 * / Cr source, so a withdrawal mislabelled as a deposit would still produce an
 * entry of the same shape. What would break is the *eligibility rule*. A deposit
 * requires the destination to be cash/bank and leaves the source free; a
 * withdrawal is the reverse. Submitting a withdrawal to /deposits would therefore
 * be validated against the wrong side - it would demand a cash/bank account where
 * the real transaction has its offset account, and reject a perfectly valid
 * withdrawal. Letting the payload name the type would mean validating a business
 * rule against a value the client chose.
 */
class StoreCashBankTransactionRequest extends FormRequest
{
    use ValidatesTransactionAmounts;

    public function authorize(): bool
    {
        return $this->user()->can('create', CashBankTransaction::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'amount' => $this->positiveMoneyRule(),

            /*
             * Existence within the active company, here. Whether each side is
             * eligible as cash/bank or acceptable as an offset is decided by the
             * service, which re-applies the same rule under a row lock at posting
             * time - a rule checked only here would let a document be saved
             * against an account that was deactivated in the meantime.
             */
            'source_account_id' => $this->companyAccountRule(),
            'destination_account_id' => $this->companyAccountRule(),

            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * The one cross-field rule that needs no database read.
     *
     * Source and destination being the same account is decidable from the
     * payload alone, and it makes the transaction meaningless: moving money to
     * the account it came from produces a journal whose two lines sit on the
     * same account. The service repeats this check against the stored row during
     * an update, because a partial edit can create the collision that neither the
     * stored document nor the incoming payload had on its own.
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
                    'The source and destination accounts must be different. '
                    .'Moving money to the account it came from does not change anything.'
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
