<?php

namespace App\Models;

use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bank operational metadata for one account.
 *
 * Operational means what it says: the name of the institution, the account
 * number, the branch, the bank identifier. None of it affects the accounting.
 * An entry debiting this account behaves identically whether the row below
 * exists, is spelled differently, or was never created - which is the correct
 * relationship between a piece of reference data and a ledger, and the reason
 * no report reads from this table.
 *
 * There is no column here for a password, PIN, OTP, CVV or card secret, and no
 * code path that would write one. Reconciliation, statement import and gateway
 * integration are absent for the same reason. The schema does not merely decline
 * to store credentials; it has nowhere to put them.
 *
 * Existence implies the account is a bank account. CashBankAccountService
 * refuses to create a row for an account whose cash_bank_kind is not BANK, so
 * "this account has bank details" and "this account is a bank account" cannot
 * drift apart.
 *
 * No global company scope, matching Account and every other model in this
 * project: company filtering is explicit, and the route binding for `account`
 * is scoped to the active company in AppServiceProvider.
 */
#[Fillable([
    'account_name',
    'bank_name',
    'account_number',
    'branch',
    'bank_identifier',
])]
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The accounts row these details describe.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
