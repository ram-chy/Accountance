<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * The one place that decides which account a transactional document may use.
 *
 * The Phase 5 spec (section 23) requires four things of every account a
 * transaction touches: it exists, it belongs to the active company, it is
 * active, and it is appropriate for the purpose. This service enforces all four,
 * in one implementation, so a controller and a posting service cannot disagree
 * about what counts as a valid receivable account.
 *
 * A single generic resolve() is deliberate. Six near-identical methods
 * (resolveReceivableAccount, resolveRevenueAccount, ...) would each be a place
 * where a rule could be loosened by accident, and the interesting part - which
 * account types are acceptable for which role - would be spread across method
 * bodies instead of living in one table. The role and the accepted types are
 * declared together below, and the message a user sees is derived from the role
 * so the validation error names the field they submitted.
 *
 * "Appropriate" is a set of account types, not a single one:
 *
 *  - Receivable / payment accounts: ASSET. Both are debits, and the Phase 5
 *    brief explicitly says cash and bank are ordinary asset accounts with
 *    balances derived by LedgerService, not a separate balance column.
 *  - Payable / output tax: LIABILITY. A tax charged to a customer is money held
 *    for a tax authority, which is a liability of this company.
 *  - Input tax: ASSET. Tax recovered from a supplier is a receivable from the
 *    tax authority, so it is debited. Allowing LIABILITY here as well would
 *    make it possible to book input tax as a reduction of what the company owes
 *    its own suppliers, which is a different account entirely.
 *  - Revenue: REVENUE. Contra-revenue (sales returns, discounts) is modelled as
 *    a revenue account with a debit normal balance, so it passes this check -
 *    that is the phase 4 contra mechanism doing its job.
 *  - Expense: EXPENSE. Same reasoning: contra-expense is an EXPENSE account with
 *    a credit normal balance.
 *
 * No account type combination is inferred from an account's name or code.
 */
class TransactionAccountResolver
{
    /**
     * Which account types each transactional role accepts.
     *
     * @var array<string, array{0: AccountType, 1: AccountType}>
     */
    private const ROLES = [
        'receivable_account_id' => [AccountType::Asset],
        'payment_account_id' => [AccountType::Asset],
        'payable_account_id' => [AccountType::Liability],
        'tax_account_id' => [AccountType::Liability],
        'input_tax_account_id' => [AccountType::Asset],
        'revenue_account_id' => [AccountType::Revenue],
        'expense_account_id' => [AccountType::Expense],
    ];

    /**
     * Resolve an account for a cash/bank transaction, requiring it to be
     * classified as holding cash or as being a bank account.
     *
     * Deliberately not one of the ROLES above. That table answers "which account
     * *type* is appropriate", and type is not the question here: a bank account
     * and a receivable are both ASSET, and only the first may take part in a
     * transfer. Adding it to the table would mean adding an accepted-type set
     * that is correct for every account of that type and still insufficient,
     * because the real test is accounts.cash_bank_kind.
     *
     * The same three checks every other role gets - ownership, active status,
     * appropriateness - are applied in the same order and produce the same shape
     * of message, so a caller cannot tell which service decided and a user cannot
     * be told about an account in another company.
     *
     * @throws ValidationException
     */
    public function cashBank(Company $company, int $accountId, string $field): Account
    {
        $account = Account::query()
            ->where('company_id', $company->getKey())
            ->whereKey($accountId)
            ->first();

        // Same reasoning as resolve(): a company-scoped miss cannot distinguish
        // "no such account" from "another company's", and must not.
        if ($account === null) {
            throw ValidationException::withMessages([
                $field => 'The selected account does not belong to the active company.',
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                $field => "Account [{$account->code} {$account->name}] is inactive and cannot be used on a transaction.",
            ]);
        }

        if (! $account->isCashBankAccount()) {
            throw ValidationException::withMessages([
                $field => sprintf(
                    'Account [%s %s] is not a cash or bank account. '
                    .'Mark it as CASH or BANK before using it on a cash/bank transaction.',
                    $account->code,
                    $account->name
                ),
            ]);
        }

        return $account;
    }

    /**
     * Resolve the non-cash/bank side of a deposit or withdrawal.
     *
     * This is the "counter-account" a user's own money arrives from or is sent
     * to: capital introduced, a bank charge, an expense. Any active account in
     * the company may serve, because which one is correct depends entirely on the
     * business transaction and this phase refuses to guess. That refusal is the
     * reason this method exists as a lookup rather than a rule: it checks that
     * the account exists, belongs here and is usable, and expresses no opinion
     * about what it should be.
     *
     * @throws ValidationException
     */
    public function offset(Company $company, int $accountId, string $field): Account
    {
        $account = Account::query()
            ->where('company_id', $company->getKey())
            ->whereKey($accountId)
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                $field => 'The selected account does not belong to the active company.',
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                $field => "Account [{$account->code} {$account->name}] is inactive and cannot be used on a transaction.",
            ]);
        }

        return $account;
    }

    /**
     * Human labels for the error message, so "is not a revenue account" reads
     * as a statement about the account's purpose rather than an enum value.
     *
     * @var array<string, string>
     */
    private const ROLE_LABELS = [
        'receivable_account_id' => 'receivable',
        'payment_account_id' => 'cash or bank',
        'payable_account_id' => 'payable',
        'tax_account_id' => 'tax liability',
        'input_tax_account_id' => 'input tax',
        'revenue_account_id' => 'revenue',
        'expense_account_id' => 'expense',
    ];

    /**
     * Roles whose name differs from the field a client actually submits.
     *
     * A validation error is keyed by field name, and the field is what a client
     * uses to find the offending input. Input tax is the one case where the role
     * name and the field name diverge: a bill posts its tax account to the
     * `tax_account_id` column and a client sends `tax_account_id`, while the role
     * is named `input_tax_account_id` to keep it distinct from the sales tax role
     * in the table above.
     *
     * Without this alias a user who sends tax_account_id: 42 on a bill is told
     * about input_tax_account_id, a field that appears nowhere in their request
     * and in no Phase 5 response body - an error they cannot act on.
     *
     * @var array<string, string>
     */
    private const ROLE_FIELDS = [
        'input_tax_account_id' => 'tax_account_id',
    ];

    /**
     * The article that reads correctly in front of each role label.
     *
     * Vowel-initial labels only; the default is "a". Kept as a table rather than
     * derived, because this is a closed vocabulary chosen by this application and
     * an explicit table cannot be broken by someone adding a label later without
     * noticing that the article went with it.
     *
     * @var array<string, string>
     */
    private const ROLE_ARTICLES = [
        'input_tax_account_id' => 'an',
        'expense_account_id' => 'an',
    ];

    /**
     * The "an expense account is required" phrase for a role.
     *
     * Returned as a whole phrase rather than assembled at the call site because the
     * two halves have to agree: the template around it supplies no article of its
     * own, so a caller that wrote "A %s account is required" around an article that
     * is already in the table produces "A a tax liability account".
     */
    private function requiredAccountPhrase(string $role): string
    {
        $label = self::ROLE_LABELS[$role];

        return ucfirst((self::ROLE_ARTICLES[$role] ?? 'a').' '.$label.' account');
    }

    /**
     * The article for an arbitrary word, used when naming the account's own type.
     *
     * Safe here because the vocabulary is closed: the words are AccountType values
     * and the role labels above, all of which take "a" or "an" by initial letter
     * with no exceptions ("an asset", "an expense", "an equity", "a liability",
     * "a receivable", "a revenue").
     */
    private function article(string $word): string
    {
        return in_array(strtolower($word[0]), ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    }

    /**
     * Resolve and validate an account for a transactional role.
     *
     * @param  string  $role  a key of self::ROLES
     *
     * @throws ValidationException
     */
    public function resolve(Company $company, string $role, int $accountId): Account
    {
        if (! array_key_exists($role, self::ROLES)) {
            throw new \InvalidArgumentException("Unknown transaction account role [{$role}].");
        }

        $account = Account::query()
            ->where('company_id', $company->getKey())
            ->whereKey($accountId)
            ->first();

        /*
         * Ownership and existence share one branch on purpose. A company-scoped
         * query that finds nothing cannot distinguish "no such account" from
         * "an account that exists but belongs to another tenant", and the reply
         * must not: telling a caller that id 42 exists in another company is
         * the disclosure Phase 4's route binding deliberately avoids with a 404.
         */
        $field = self::ROLE_FIELDS[$role] ?? $role;

        if ($account === null) {
            throw ValidationException::withMessages([
                $field => 'The selected account does not belong to the active company.',
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                $field => "Account [{$account->code} {$account->name}] is inactive and cannot be used on a transaction.",
            ]);
        }

        if (! in_array($account->account_type, self::ROLES[$role], true)) {
            throw ValidationException::withMessages([
                $field => sprintf(
                    'Account [%s %s] is %s %s account. %s is required for this transaction.',
                    $account->code,
                    $account->name,
                    $this->article($actualType = strtolower($account->account_type->value)),
                    $actualType,
                    $this->requiredAccountPhrase($role)
                ),
            ]);
        }

        return $account;
    }

    /**
     * Resolve a receivable account for a customer or sales invoice.
     *
     * @throws ValidationException
     */
    public function receivable(Company $company, int $accountId): Account
    {
        return $this->resolve($company, 'receivable_account_id', $accountId);
    }

    /**
     * Resolve a cash/bank account for a receipt or payment.
     *
     * @throws ValidationException
     */
    public function payment(Company $company, int $accountId): Account
    {
        return $this->resolve($company, 'payment_account_id', $accountId);
    }

    /**
     * Resolve a payable account for a supplier or purchase bill.
     *
     * @throws ValidationException
     */
    public function payable(Company $company, int $accountId): Account
    {
        return $this->resolve($company, 'payable_account_id', $accountId);
    }

    /**
     * Resolve a sales tax liability account.
     *
     * @throws ValidationException
     */
    public function tax(Company $company, int $accountId): Account
    {
        return $this->resolve($company, 'tax_account_id', $accountId);
    }

    /**
     * Resolve an input tax account for a purchase bill.
     *
     * @throws ValidationException
     */
    public function inputTax(Company $company, int $accountId): Account
    {
        return $this->resolve($company, 'input_tax_account_id', $accountId);
    }

    /**
     * Resolve a revenue account for an invoice line.
     *
     * @throws ValidationException
     */
    public function revenue(Company $company, int $accountId): Account
    {
        return $this->resolve($company, 'revenue_account_id', $accountId);
    }

    /**
     * Resolve an expense account for a purchase bill line.
     *
     * @throws ValidationException
     */
    public function expense(Company $company, int $accountId): Account
    {
        return $this->resolve($company, 'expense_account_id', $accountId);
    }

    /**
     * Resolve one account per role at once, reporting all failures together.
     *
     * A document with a receivable, a tax and three revenue accounts would
     * otherwise fail one account at a time - five round trips to discover that
     * two of them were wrong, and a client retrying the request each time.
     *
     * This method is for *distinct roles*. The same role appearing twice is a
     * mistake, not a duplicate, because the returned array is keyed by role and
     * a second entry would silently overwrite the first. For the real
     * many-accounts-one-role case - the revenue accounts on an invoice's lines -
     * use resolveAll() instead.
     *
     * @param  array<string, int>  $accountIdsByRole  role => account id
     * @return array<string, Account> role => resolved account
     *
     * @throws ValidationException
     */
    public function resolveMany(Company $company, array $accountIdsByRole): array
    {
        $errors = [];
        $resolved = [];

        foreach ($accountIdsByRole as $role => $accountId) {
            if (! array_key_exists($role, self::ROLES)) {
                throw new \InvalidArgumentException("Unknown transaction account role [{$role}].");
            }

            try {
                $resolved[$role] = $this->resolve($company, (string) $role, $accountId);
            } catch (ValidationException $e) {
                $errors = array_merge($errors, $e->errors());
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * Resolve a list of account ids that all share one role, reporting all failures.
     *
     * An invoice with 40 lines commonly names the same two or three revenue
     * accounts over and over. Validating line by line would issue 40 queries and
     * produce 40 copies of the same error; de-duplicating first produces two
     * queries and one error, and the returned map is keyed by id so the caller
     * can look an account up per line without re-querying.
     *
     * The error key is the role plus the offending id, so a client reading the
     * 422 can tell which account is wrong. The line index is deliberately not
     * used: reporting "lines.7" for an account that also appears on lines 8, 9
     * and 10 would be a claim about one line when the problem is the account.
     *
     * @param  array<int, int>  $accountIds
     * @return array<int, Account> account id => resolved account
     *
     * @throws ValidationException
     */
    public function resolveAll(Company $company, string $role, array $accountIds): array
    {
        if (! array_key_exists($role, self::ROLES)) {
            throw new \InvalidArgumentException("Unknown transaction account role [{$role}].");
        }

        $errors = [];
        $resolved = [];

        foreach (array_unique($accountIds) as $accountId) {
            try {
                $resolved[$accountId] = $this->resolve($company, $role, $accountId);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    $errors[$role] = array_merge($errors[$role] ?? [], $messages);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }
}
