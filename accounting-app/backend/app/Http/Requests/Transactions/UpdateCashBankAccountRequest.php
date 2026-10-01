<?php

namespace App\Http\Requests\Transactions;

use App\Enums\CashBankKind;
use App\Enums\PermissionName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update an account's cash/bank classification and its bank details.
 *
 * The subject is an existing account in the chart of accounts, so the route binds
 * to Account. Nothing here validates whether a given change is *permissible* -
 * that an account with bank details cannot become cash, that an account with
 * accounting history keeps its classification - because those rules are business
 * rules with a second implementation point, and they live in
 * CashBankAccountService. A rule duplicated here would be a rule that could
 * disagree with the service.
 *
 * The bank block is optional as a whole, because a cash account has no bank
 * details. When it is supplied the fields inside it are validated; whether it may
 * be supplied at all is the service's question, since only an account classified
 * as BANK can carry one.
 *
 * authorize() checks the permission directly rather than a policy method. A
 * policy is resolved per model class and Account already has AccountPolicy, so a
 * second policy for Account could never be reached through `$user->can()` and
 * would look like it enforced something while enforcing nothing. The membership
 * half of the check is not skipped: the `account` route binding is scoped to the
 * active company, so an Account from another company has already 404ed.
 */
class UpdateCashBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(PermissionName::CashBankUpdate->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * `nullable` rather than just `sometimes`, so that sending
             * cash_bank_kind: null is a way to *remove* a classification rather
             * than a value that fails the enum check. Whether the removal is
             * allowed - the account may have bank details, or accounting history -
             * is decided by the service.
             */
            'cash_bank_kind' => ['sometimes', 'nullable', 'string', Rule::in(CashBankKind::values())],

            'bank_account' => ['sometimes', 'nullable', 'array'],
            'bank_account.account_name' => ['required_with:bank_account', 'string', 'max:255'],
            'bank_account.bank_name' => ['required_with:bank_account', 'string', 'max:255'],
            'bank_account.account_number' => ['nullable', 'string', 'max:100'],
            'bank_account.branch' => ['nullable', 'string', 'max:255'],
            'bank_account.bank_identifier' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cash_bank_kind.in' => 'The cash/bank classification must be CASH or BANK, or left empty.',
            'bank_account.account_name.required_with' => 'The bank account name is required when bank details are provided.',
            'bank_account.bank_name.required_with' => 'The bank name is required when bank details are provided.',
        ];
    }
}
