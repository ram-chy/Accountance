<?php

namespace App\Http\Requests\Accounting;

use App\Enums\AccountType;
use App\Enums\CashBankKind;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update an existing account in the active company.
 *
 * Note `authorize()` uses the policy method name, not the permission string.
 * See StoreAccountRequest for why that distinction matters.
 */
class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Account $account */
        $account = $this->route('account');

        return $this->user()->can('update', $account);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();
        /** @var Account $account */
        $account = $this->route('account');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9._\- ]+$/',
                // ignore() so saving an account without changing its code does
                // not collide with itself.
                Rule::unique('accounts', 'code')
                    ->where('company_id', $company->getKey())
                    ->ignore($account->getKey()),
            ],
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('accounts', 'name')
                    ->where('company_id', $company->getKey())
                    ->ignore($account->getKey()),
            ],
            'account_type' => ['sometimes', 'string', Rule::in(AccountType::values())],
            // Phase 7. `nullable` rather than just `sometimes`: sending
            // cash_bank_kind: null is how a user removes a classification from an
            // account that has never been used, and without `nullable` that
            // request would be rejected as an invalid value rather than clearing
            // the field.
            'cash_bank_kind' => ['sometimes', 'nullable', 'string', Rule::in(CashBankKind::values())],
            'normal_balance' => ['sometimes', 'nullable', 'string', Rule::in(NormalBalance::values())],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')
                    ->where('company_id', $company->getKey())
                    /*
                     * A parent equal to the account itself is refused here as
                     * well as in AccountService. Two places is defensible for
                     * this one: the message reaches the user attached to the
                     * field, and the service check protects non-HTTP callers.
                     */
                    ->where('id', '!=', $account->getKey()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The account code may contain letters, numbers, dots, dashes and spaces only.',
            'parent_id.exists' => 'The selected parent account does not exist in the active company.',
        ];
    }
}
