<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Enums\FixedAssetAcquisitionMethod;
use App\Enums\NormalBalance;
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
 *
 * Phase 12 adds the five fixed-asset roles. They are declared here, in this
 * table, for the same reason everything else is: "a fixed asset posts to an
 * ASSET, its accumulated depreciation to a contra ASSET, its depreciation charge
 * to an EXPENSE, its disposal gain to a REVENUE and its disposal loss to an
 * EXPENSE" is a rule about account types, and a rule about account types living
 * anywhere else would be a second answer to the same question.
 *
 * The one thing a type cannot express is that accumulated depreciation must
 * additionally be CREDIT-normal, so that is checked by accumulatedDepreciation()
 * below rather than smuggled in here as a fake account type.
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

        /*
         * Phase 12 - fixed assets.
         *
         * The asset account is ASSET, not merely "not a liability": an asset
         * capitalised into a revenue account would credit revenue while showing
         * nothing on the balance sheet, and the error would surface months later as
         * a balance sheet that does not foot.
         *
         * Gain on disposal is a REVENUE and loss on disposal is an EXPENSE, which
         * is why they are two roles rather than one "disposal_result_account". They
         * sit on opposite sides of the profit and loss statement, they mean
         * different things in a report, and a single column would let a category
         * be configured to book every disposal to gains and hide every loss.
         */
        'asset_account_id' => [AccountType::Asset],
        'accumulated_depreciation_account_id' => [AccountType::Asset],
        'depreciation_expense_account_id' => [AccountType::Expense],
        'gain_on_disposal_account_id' => [AccountType::Revenue],
        'loss_on_disposal_account_id' => [AccountType::Expense],

        /*
         * Phase 12. Both are ASSET, and the reason is the same for each: money
         * arriving is an asset whatever form it arrives in. The acquisition side is
         * dispatched more narrowly by acquisitionAccount() - cash/bank only, or a
         * liability - because the method on the asset already says which of the two
         * applies. The proceeds side is checked only as ASSET, because a disposal's
         * proceeds may legitimately land in a receivable.
         */
        'proceeds_account_id' => [AccountType::Asset],
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

        /*
         * Phase 12. The labels are what the "is a <type> account" message reads as,
         * so they name the purpose rather than the account type - a user told
         * "Account [1500 Motor Vehicles] is a revenue account. A fixed asset account
         * is required" understands both halves, where the enum value alone would
         * not.
         */
        'asset_account_id' => 'fixed asset',
        'accumulated_depreciation_account_id' => 'accumulated depreciation',
        'depreciation_expense_account_id' => 'depreciation expense',
        'gain_on_disposal_account_id' => 'gain on disposal',
        'loss_on_disposal_account_id' => 'loss on disposal',
        'proceeds_account_id' => 'proceeds',
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

        /*
         * Phase 12. "an accumulated depreciation account" and "an expense account"
         * - both vowel-initial, and both easy to get wrong by copy-pasting the
         * default "a" into a phrase that is then read aloud in a support call.
         */
        'accumulated_depreciation_account_id' => 'an',
        'depreciation_expense_account_id' => 'an',

        // Phase 12. "a proceeds account" - vowel-initial, same reason as above.
        'proceeds_account_id' => 'a',
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
     * Resolve the account a fixed asset's cost is capitalised into.
     *
     * @throws ValidationException
     */
    public function fixedAsset(Company $company, int $accountId): Account
    {
        return $this->resolve($company, 'asset_account_id', $accountId);
    }

    /**
     * Resolve an asset's accumulated depreciation account.
     *
     * Everything resolve() checks applies - it must exist, belong to this company
     * and be an active ASSET - and one more thing, which is the whole point of
     * this method existing separately: it must be CREDIT-normal.
     *
     * Accumulated depreciation is a contra account. Phase 4 put the mechanism for
     * that in place with accounts.normal_balance rather than a special account type,
     * and Phase 6's balance sheet already signs a contra asset on its type's debit
     * side so it reduces the asset section. That machinery works whether or not
     * anybody validated the account, which is exactly why the check has to be made
     * here: a DEBIT-normal asset account named "Accumulated Depreciation" would
     * post every depreciation charge as a debit, and would then *add* to the asset
     * section of the balance sheet instead of reducing it. Nothing downstream
     * would complain - the trial balance would still foot, because the error is in
     * the classification, not the arithmetic.
     *
     * @throws ValidationException
     */
    public function accumulatedDepreciation(Company $company, int $accountId): Account
    {
        $account = $this->resolve($company, 'accumulated_depreciation_account_id', $accountId);

        if ($account->normalBalance() !== NormalBalance::Credit) {
            throw ValidationException::withMessages([
                'accumulated_depreciation_account_id' => sprintf(
                    'Account [%s %s] is a debit-normal account, so a depreciation charge posted to it '
                    .'would increase the asset instead of reducing it. Set its normal balance to CREDIT '
                    .'so it is treated as a contra account.',
                    $account->code,
                    $account->name
                ),
            ]);
        }

        return $account;
    }

    /**
     * Resolve an asset's depreciation expense account.
     *
     * @throws ValidationException
     */
    public function depreciationExpense(Company $company, int $accountId): Account
    {
        return $this->resolve($company, 'depreciation_expense_account_id', $accountId);
    }

    /**
     * Resolve the account a disposal gain is booked to.
     *
     * @throws ValidationException
     */
    public function gainOnDisposal(Company $company, int $accountId): Account
    {
        return $this->resolve($company, 'gain_on_disposal_account_id', $accountId);
    }

    /**
     * Resolve the account a disposal loss is booked to.
     *
     * @throws ValidationException
     */
    public function lossOnDisposal(Company $company, int $accountId): Account
    {
        return $this->resolve($company, 'loss_on_disposal_account_id', $accountId);
    }

    /**
     * Resolve the money side of a fixed asset's acquisition.
     *
     * Dispatches on the asset's acquisition method rather than accepting an account
     * and asking the caller what it is for, because the method is already a field on
     * the asset and the two must agree. Passing an account here asks the same
     * question the existing cashBank() and payable() ask, and reusing them is the
     * point: the "is this account classified as cash or bank" rule and the "is this
     * account a liability" rule are each written once in this class, not once per
     * phase that spends money.
     *
     * The two branches differ in the strictness they apply, and it is worth being
     * explicit about why. A cash purchase must name a specific cash or bank account,
     * because that is where the money left from - there is no abstraction to fall
     * back on and no reason to allow it. A supplier credit must name a LIABILITY, but
     * not necessarily the company's configured payable account: it only has to be an
     * account that represents owing money, because which payable the liability is
     * recorded in is a choice the company makes elsewhere.
     *
     * @throws ValidationException
     */
    public function acquisitionAccount(
        Company $company,
        int $accountId,
        FixedAssetAcquisitionMethod $method,
        string $field = 'acquisition_account_id'
    ): Account {
        if ($method->requiresCashBankAccount()) {
            return $this->cashBank($company, $accountId, $field);
        }

        return $this->payable($company, $accountId, $field);
    }

    /**
     * Resolve where a disposal's proceeds are received.
     *
     * Any active ASSET account may serve, and the check is deliberately narrower
     * than cashBank()'s: the money may land in a bank account immediately or sit in
     * the company's receivables as an amount owed by the buyer, and both are assets.
     *
     * A receivable account passes without being configured as the company's
     * receivable, which is the same latitude payable() takes on the other side of
     * this phase. Requiring the exact configured account would mean a company with a
     * single-arrears-ledger setup could not sell anything on credit, and the request
     * carries the fact of whether the buyer has paid yet - so this method has nothing
     * to be strict about that the caller has not already stated.
     *
     * @throws ValidationException
     */
    public function proceedsAccount(Company $company, int $accountId, string $field = 'proceeds_account_id'): Account
    {
        return $this->resolve($company, 'proceeds_account_id', $accountId);
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
