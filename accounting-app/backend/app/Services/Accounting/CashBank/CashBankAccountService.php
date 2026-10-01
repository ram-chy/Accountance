<?php

namespace App\Services\Accounting\CashBank;

use App\Enums\CashBankKind;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Which accounts are cash, which are banks, and their bank details.
 *
 * Phase 7 needed this and found nothing to reuse, because Phase 4 built a chart
 * of accounts with no notion of what an account is *for* beyond its type. Two
 * pieces of information live here:
 *
 *  1. accounts.cash_bank_kind - the classification. A one-column write to an
 *     existing model, deliberately not a new account type and not a new table,
 *     for reasons recorded in the migration.
 *
 *  2. bank_accounts - optional operational detail for an account already
 *     classified as BANK. Nothing here is read by any report or by any posting
 *     path; deleting the row would not change a single figure in the ledger,
 *     which is the correct relationship between reference data and an account.
 *
 * What this service deliberately does NOT do is maintain a balance, a running
 * total or an opening figure for any account. There is nowhere to store one,
 * and that is the point: a cash balance is a question about journal lines, asked
 * of LedgerService, and it is answered identically for a cash account and for a
 * bank account.
 */
class CashBankAccountService
{
    /**
     * Classify an existing account as holding cash, or as being a bank account.
     *
     * The account has to exist in this company and be active. Setting a kind on
     * an inactive account is refused because the classification would then be
     * unusable - there is no posting path that would accept it - and a setting
     * that cannot take effect should be reported as an error rather than stored
     * quietly.
     *
     * @throws ValidationException
     */
    public function markAs(Company $company, Account $account, CashBankKind $kind): Account
    {
        $this->assertBelongsToCompany($company, $account);
        $this->assertActive($account);

        /*
         * Refuse to mark a bank account as cash. Re-categorising an account that
         * already carries bank details would leave those details describing an
         * account that is no longer a bank account, and the two statements would
         * disagree. The caller must clear the bank details first, which is an
         * explicit act rather than something a mis-click could do.
         */
        if ($kind === CashBankKind::Cash && $account->bankAccount()->exists()) {
            throw ValidationException::withMessages([
                'cash_bank_kind' => 'This account has bank details recorded against it and cannot be '
                    .'reclassified as cash. Remove the bank details first.',
            ]);
        }

        $account->cash_bank_kind = $kind;
        $account->save();

        return $account->refresh();
    }

    /**
     * Remove a cash/bank classification.
     *
     * Refused once the account has been used by a posted transaction. The reason
     * is that a transfer posted yesterday happened *because* this account was
     * cash; removing the label afterwards would leave a posted journal entry
     * referring to two accounts that are no longer a cash/bank pair, and the
     * ledger's story about that movement would no longer be recoverable from the
     * ledger alone.
     *
     * @throws ValidationException
     */
    public function clear(Company $company, Account $account): Account
    {
        $this->assertBelongsToCompany($company, $account);

        if ($account->cash_bank_kind === null) {
            return $account->refresh();
        }

        if ($account->journalLines()->exists()) {
            throw ValidationException::withMessages([
                'account' => 'This account has accounting history and keeps its cash/bank '
                    .'classification. Deactivate it instead if it is no longer in use.',
            ]);
        }

        if ($account->bankAccount()->exists()) {
            throw ValidationException::withMessages([
                'account' => 'This account has bank details recorded against it. '
                    .'Remove those before clearing the classification.',
            ]);
        }

        $account->cash_bank_kind = null;
        $account->save();

        return $account->refresh();
    }

    /**
     * Record or update the bank details for an account.
     *
     * One row per account, enforced by a unique index and here by upsert-on-
     * account semantics: calling this twice for the same account updates the
     * existing details rather than creating a second, contradictory set.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function saveBankDetails(Company $company, Account $account, array $data): BankAccount
    {
        $this->assertBelongsToCompany($company, $account);

        if ($account->cash_bank_kind !== CashBankKind::Bank) {
            throw ValidationException::withMessages([
                'account_id' => 'Bank details can only be recorded against an account classified as BANK. '
                    .'Classify this account as BANK first.',
            ]);
        }

        $bankAccount = $account->bankAccount()->first();

        if ($bankAccount === null) {
            $bankAccount = new BankAccount;
        }

        $bankAccount->fill([
            'account_name' => $data['account_name'],
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'] ?? null,
            'branch' => $data['branch'] ?? null,
            'bank_identifier' => $data['bank_identifier'] ?? null,
        ]);

        // Not mass-assignable: the account belongs to a company, and is_active is
        // a lifecycle transition rather than a field a create call sets.
        $bankAccount->company_id = $company->getKey();
        $bankAccount->account_id = $account->getKey();
        $bankAccount->forceFill(['is_active' => true]);
        $bankAccount->save();

        return $bankAccount->refresh();
    }

    /**
     * Remove bank details for an account.
     *
     * @throws ValidationException
     */
    public function deleteBankDetails(Company $company, Account $account): void
    {
        $this->assertBelongsToCompany($company, $account);

        $account->bankAccount()->delete();
    }

    /**
     * Activate or deactivate an account's bank details.
     *
     * Separate from accounts.is_active on purpose. Closing a bank account stops
     * it being selected for new movements while leaving the chart of accounts
     * alone, and the two facts are genuinely different - see the migration.
     *
     * @throws ValidationException
     */
    public function setBankDetailsActive(Company $company, Account $account, bool $active): BankAccount
    {
        $this->assertBelongsToCompany($company, $account);

        $bankAccount = $account->bankAccount()->first();

        if ($bankAccount === null) {
            throw ValidationException::withMessages([
                'account' => 'This account has no bank details to activate or deactivate.',
            ]);
        }

        $bankAccount->forceFill(['is_active' => $active])->save();

        return $bankAccount->refresh();
    }

    /**
     * The cash/bank accounts available for a new movement.
     *
     * Only accounts whose bank details, where they have any, are themselves
     * active. An account whose bank row says is_active = false is excluded even
     * though accounts.is_active is still true, because a closed bank account
     * should not be selectable before anyone remembers to deactivate it in the
     * chart of accounts.
     *
     * Eager-loads bankAccount so the listing is a fixed number of queries
     * rather than one per account.
     *
     * @return Collection<int, Account>
     */
    public function listFor(Company $company, ?CashBankKind $kind = null)
    {
        return Account::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->whereNotNull('cash_bank_kind')
            ->when($kind, fn ($query) => $query->where('cash_bank_kind', $kind->value))
            ->where(function ($query) {
                $query->whereDoesntHave('bankAccount')
                    ->orWhereHas('bankAccount', fn ($q) => $q->where('is_active', true));
            })
            ->with('bankAccount')
            ->orderBy('code')
            ->get();
    }

    /**
     * @throws ValidationException
     */
    private function assertBelongsToCompany(Company $company, Account $account): void
    {
        if ($account->company_id !== $company->getKey()) {
            throw ValidationException::withMessages([
                'account_id' => 'The selected account does not belong to the active company.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertActive(Account $account): void
    {
        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'account_id' => "Account [{$account->code} {$account->name}] is inactive. "
                    .'Activate it before classifying it.',
            ]);
        }
    }
}
