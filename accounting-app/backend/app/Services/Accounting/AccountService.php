<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Enums\CashBankKind;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Chart of Accounts management.
 *
 * Two rules carry more weight than the rest and are enforced here rather than
 * only in the request layer:
 *
 *  1. A parent must belong to the same company and must not create a cycle.
 *  2. An account with journal history can never be deleted, only deactivated.
 */
class AccountService
{
    public function __construct(
        private readonly AccountingRules $rules,
        /*
         * Phase 7. Injected only so that a cash/bank reclassification coming
         * through this generic update path goes through the same checks as one
         * coming through the cash/bank endpoint. There is no cycle:
         * CashBankAccountService does not depend on AccountService.
         */
        private readonly CashBank\CashBankAccountService $cashBank,
    ) {}

    /**
     * @throws ValidationException
     */
    public function create(Company $company, array $data): Account
    {
        $this->assertParentIsUsable($company, $data['parent_id'] ?? null);

        $account = new Account([
            'code' => $data['code'],
            'name' => $data['name'],
            'account_type' => $data['account_type'],
            'cash_bank_kind' => $data['cash_bank_kind'] ?? null,
            'normal_balance' => $data['normal_balance'] ?? null,
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
        ]);

        // is_active is deliberately not mass-assignable: a new account is active
        // by definition, and is_system is owned by future modules.
        $account->company_id = $company->getKey();
        $account->forceFill(['is_active' => true, 'is_system' => false]);

        try {
            $account->save();
        } catch (QueryException $e) {
            $this->throwIfDuplicate($e, $company, $data);
        }

        return $account;
    }

    /**
     * @throws ValidationException
     */
    public function update(Account $account, array $data): Account
    {
        $this->assertParentIsUsable(
            $account->company,
            $data['parent_id'] ?? null,
            excluding: $account
        );

        /*
         * Phase 7 made this the first method in the service that can perform more
         * than one write: a cash_bank_kind change goes through CashBankAccountService,
         * which saves, before the account is saved again below. Transactional so
         * that a duplicate code or name rejected by the final save cannot leave
         * the reclassification committed on its own.
         */
        return DB::transaction(function () use ($account, $data) {
            $account->fill(array_filter([
                'code' => $data['code'] ?? null,
                'name' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
            ], fn ($value) => $value !== null));

            // account_type, normal_balance and parent are handled explicitly:
            // changing any of them can invalidate an existing hierarchy or flip the
            // meaning of posted history, so each gets its own validation rather than
            // being folded into the generic fill.
            if (array_key_exists('account_type', $data)) {
                $account->account_type = $data['account_type'];
            }

            if (array_key_exists('normal_balance', $data)) {
                $account->normal_balance = $data['normal_balance'];
            }

            /*
             * Phase 7. Treated like account_type and normal_balance rather than
             * being folded into the generic fill, because a reclassification is a
             * statement about what the account *is* and carries rules the generic
             * fill has no way to express: an account with bank details cannot
             * become cash, and an account with accounting history keeps its kind.
             *
             * Those rules live in CashBankAccountService, and this delegates to it
             * rather than restating them. That is the whole reason this is not
             * simply another `if (array_key_exists(...))` assignment - an
             * assignment here would let PUT /api/accounts/{account} do something
             * the cash/bank endpoint refuses, and the two paths would then
             * disagree about the same account.
             */
            if (array_key_exists('cash_bank_kind', $data)) {
                $requested = $data['cash_bank_kind'] === null
                    ? null
                    : CashBankKind::from($data['cash_bank_kind']);

                if ($requested !== $account->cash_bank_kind) {
                    $account = $requested === null
                        ? $this->cashBank->clear($account->company, $account)
                        : $this->cashBank->markAs($account->company, $account, $requested);
                }
            }

            if (array_key_exists('parent_id', $data)) {
                $account->parent_id = $data['parent_id'];
            }

            // Lets the duplicate-key handler tell "you changed nothing" apart from
            // "you collided with another account".
            $data['_account_id'] = $account->getKey();

            try {
                $account->save();
            } catch (QueryException $e) {
                $this->throwIfDuplicate($e, $account->company, $data);
            }

            return $account->refresh();
        });
    }

    public function activate(Account $account): Account
    {
        $account->forceFill(['is_active' => true])->save();

        return $account->refresh();
    }

    /**
     * Deactivate an account.
     *
     * Deactivation is the reversible, history-preserving alternative to
     * deletion: the account stops accepting new postings but keeps every posted
     * line that references it.
     */
    public function deactivate(Account $account): Account
    {
        $account->forceFill(['is_active' => false])->save();

        return $account->refresh();
    }

    /**
     * Delete an account, but only if it has never been used.
     *
     * The rule is "any journal line", not "any posted line": deleting an account
     * that a draft references would orphan those lines, and a draft is not a
     * record anyone would notice was quietly rewritten. The database FK also
     * uses restrictOnDelete as a backstop.
     *
     * @throws ValidationException
     */
    public function delete(Account $account): void
    {
        if ($account->hasJournalHistory()) {
            throw ValidationException::withMessages([
                'account' => 'This account cannot be deleted because it has accounting history. '
                    .'Deactivate it instead.',
            ]);
        }

        /*
         * An account with children cannot be deleted either, or those children
         * would be silently re-parented to the deleted account's parent,
         * restructuring the chart as a side effect of a delete.
         */
        if ($account->children()->exists()) {
            throw ValidationException::withMessages([
                'account' => 'This account cannot be deleted because it has sub-accounts. '
                    .'Delete or move them first.',
            ]);
        }

        $account->delete();
    }

    /**
     * The effective normal balance for an account, asking the central rules.
     */
    public function normalBalanceFor(Account $account): NormalBalance
    {
        return $this->rules->normalBalanceFor($account);
    }

    /**
     * Assert the account may be used for a new posting.
     *
     * An inactive account keeps its history and stays visible in reports, but
     * accepting new entries would let a retired chart-of-accounts item be used
     * to move money.
     *
     * @throws ValidationException
     */
    public function assertUsableForPosting(Account $account): void
    {
        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'account_id' => "Account [{$account->code} {$account->name}] is inactive and cannot be posted to.",
            ]);
        }
    }

    /**
     * A parent must be in the same company and must not create a cycle.
     *
     * Both halves matter. A cross-company parent would expose another tenant's
     * account name through a traversal, and a cycle would make any recursive
     * hierarchy walk loop forever.
     *
     * @throws ValidationException
     */
    private function assertParentIsUsable(Company $company, ?int $parentId, ?Account $excluding = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Account::query()->find($parentId);

        if ($parent === null || $parent->company_id !== $company->getKey()) {
            throw ValidationException::withMessages([
                'parent_id' => 'The selected parent account does not belong to the active company.',
            ]);
        }

        if ($excluding === null) {
            return;
        }

        // Walk up from the proposed parent. Meeting this account anywhere in that
        // chain would make it its own ancestor.
        $seen = [];
        $cursor = $parent;

        while ($cursor !== null) {
            if ($cursor->getKey() === $excluding->getKey()) {
                throw ValidationException::withMessages([
                    'parent_id' => 'An account cannot be placed beneath itself.',
                ]);
            }

            if (isset($seen[$cursor->getKey()])) {
                // Pre-existing cycle in the data; refuse rather than loop.
                break;
            }

            $seen[$cursor->getKey()] = true;
            $cursor = $cursor->parent_id !== null
                ? Account::query()->find($cursor->parent_id)
                : null;
        }
    }

    /**
     * Translate a uniqueness violation into a field-specific validation error.
     *
     * Accounts have two independent unique indexes per company - code and name -
     * so the database error does not say which one fired. Re-checking each
     * candidate identifies the offending field, and reporting it against the
     * right input matters: telling a user "name is taken" when they actually
     * submitted a duplicate code sends them to fix the wrong thing.
     *
     * Only an actual duplicate is translated. Any other QueryException is
     * re-thrown untouched, because a blanket catch would report "name is taken"
     * for a foreign-key violation, a CHECK constraint, or a connection failure
     * - telling the user to fix a field that was never the problem and hiding a
     * real fault from whoever has to fix it.
     *
     * This runs after the statement has failed, inside the caller's transaction
     * if there is one, so the reads below see committed data.
     *
     * @throws ValidationException
     */
    private function throwIfDuplicate(QueryException $e, Company $company, array $data): void
    {
        if (! $this->isDuplicateKey($e)) {
            throw $e;
        }

        throw $this->asValidationException($e, $company, $data);
    }

    /**
     * Is this a duplicate-entry error, as opposed to any other database failure?
     *
     * SQLSTATE 23000/1062 is MySQL's "integrity constraint violation" /
     * "duplicate entry". The CHECK constraints this schema relies on also raise
     * 23000 but with 3819, so the code is matched as well - otherwise a rejected
     * account_type would be reported as a duplicate name.
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Duplicate entry')
            || str_contains($message, 'accounts_company_id_code_unique')
            || str_contains($message, 'accounts_company_id_name_unique')
            || str_contains($message, 'accounts.code')
            || str_contains($message, 'accounts.name');
    }

    /**
     * @throws ValidationException
     */
    private function asValidationException(QueryException $e, Company $company, array $data): ValidationException
    {
        $excludingId = $data['_account_id'] ?? null;

        if (isset($data['code'])) {
            $duplicateCode = Account::query()
                ->where('company_id', $company->getKey())
                ->where('code', $data['code'])
                ->when($excludingId, fn ($query) => $query->whereKeyNot($excludingId))
                ->exists();

            if ($duplicateCode) {
                return ValidationException::withMessages([
                    'code' => 'This account code is already in use for the selected company.',
                ]);
            }
        }

        return ValidationException::withMessages([
            'name' => 'This account name is already in use for the selected company.',
        ]);
    }

    /**
     * Accounts a user may see: everything in the active company.
     *
     * Inactive accounts are included by default because historical reports need
     * them; a caller filtering for postable accounts asks for active only.
     */
    public function listFor(Company $company, bool $activeOnly = false)
    {
        return Account::query()
            ->where('company_id', $company->getKey())
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('code')
            ->get();
    }

    /**
     * Account types paired with their normal balances, for API consumers that
     * need to render a form or explain a balance without hard-coding the rule.
     *
     * @return array<string, string>
     */
    public function typeNormalBalanceMap(): array
    {
        $map = [];

        foreach (AccountType::cases() as $type) {
            $map[$type->value] = $type->normalBalance()->value;
        }

        return $map;
    }
}
