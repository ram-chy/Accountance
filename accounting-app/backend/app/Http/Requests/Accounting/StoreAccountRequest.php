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
 * Create an account in the active company.
 *
 * Authorises with `can('create', Account::class)` rather than a raw permission
 * string. A raw string is checked by Spatie directly and never reaches
 * AccountPolicy, which is exactly the class of bug that let Phase 3 ship an IDOR
 * - the permission passed while the membership half of the check was skipped.
 */
class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Account::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return [
            /*
             * Uniqueness is enforced in the database per company; this rule only
             * gives the user an immediate, field-level error. Both are needed:
             * the rule is friendlier, the unique index is what actually holds
             * under concurrency.
             */
            'code' => [
                'required',
                'string',
                'max:50',
                // Trimmed but not coerced to a number: account codes are
                // identifiers and a company may reasonably use "1000-A" or
                // "4100.10".
                'regex:/^[A-Za-z0-9._\- ]+$/',
                Rule::unique('accounts', 'code')->where('company_id', $company->getKey()),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('accounts', 'name')->where('company_id', $company->getKey()),
            ],
            'account_type' => ['required', 'string', Rule::in(AccountType::values())],

            /*
             * Phase 7. Nullable, and null is the ordinary case: most accounts in
             * a chart of accounts are not cash or bank. When supplied it must be
             * CASH or BANK, which is the same enum-only rule account_type gets -
             * storing an arbitrary string here would let an account claim to be a
             * kind nothing in the application understands, and every cash/bank
             * eligibility check would then have to treat it as unrecognised.
             */
            'cash_bank_kind' => ['nullable', 'string', Rule::in(CashBankKind::values())],

            /*
             * Nullable because null means "follow the account type". When it IS
             * supplied it must be a real side - storing an arbitrary string here
             * would let an account claim to be normal on a side that does not
             * exist, and every balance calculation would then be meaningless.
             */
            'normal_balance' => ['nullable', 'string', Rule::in(NormalBalance::values())],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => [
                'nullable',
                'integer',
                /*
                 * Scoped to the company's accounts. Without the where clause a
                 * client could name another company's account as a parent, and
                 * the resulting traversal would expose its code and name.
                 * AccountService independently re-checks this, because a rule that
                 * only runs in HTTP would not protect a future console command.
                 */
                Rule::exists('accounts', 'id')->where('company_id', $company->getKey()),
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
            'account_type.in' => 'The account type must be one of ASSET, LIABILITY, EQUITY, REVENUE or EXPENSE.',
            'cash_bank_kind.in' => 'The cash/bank classification must be CASH or BANK, or left empty.',
            'normal_balance.in' => 'The normal balance must be DEBIT or CREDIT, or left empty.',
            'parent_id.exists' => 'The selected parent account does not exist in the active company.',
        ];
    }
}
