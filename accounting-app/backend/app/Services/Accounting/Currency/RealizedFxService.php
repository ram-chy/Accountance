<?php

namespace App\Services\Accounting\Currency;

use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyFxSetting;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Realised foreign exchange: the difference between what a balance carried and what
 * settling it actually moved.
 *
 * THE ACCOUNTING, IN ONE PLACE
 *
 * A receivable was raised at the rate in force on the invoice date. It is settled at
 * the rate in force on the receipt date. If the rate moved in between, the cash that
 * arrives is not worth what the receivable was carried at, and the gap has to land
 * somewhere. It lands in realised FX gain or loss, and the rule for which is the
 * whole content of this class:
 *
 *     realised gain = base value received  -  base value carried
 *
 * Nothing else. Both settlement posting services call compute() and then act on the
 * result, so the definition of "gain" exists once - a signed difference is exactly
 * the kind of thing two services will implement in opposite orders, and when they do
 * every FX posting in the company is mirrored.
 *
 * WHY A REALIZED POSTING AND NOT A REVALUATION
 *
 * The receivable's carrying amount in the base ledger stays at the rate it was
 * booked at. Only the settlement is re-converted, and the difference is realised at
 * that moment. This is what makes a posted document immutable: no posted figure ever
 * changes, and the FX result appears on the day the money moves rather than drifting
 * as a periodic adjustment nobody can trace to a transaction.
 *
 * WHY THE COMPANY MUST CONFIGURE FX ACCOUNTS BEFORE POSTING FOREIGN SETTLEMENTS
 *
 * company_fx_settings holds a gain/loss account pair. Without it there is no account
 * to post the difference to, and the alternatives are all worse than refusing:
 *
 *   - skip the FX line   -> the journal does not balance, or silently balances by
 *                           absorbing the difference into the receivable
 *   - post to a hardcoded account -> every company books FX results to the same code,
 *                           which does not exist in most charts of accounts
 *   - post to the receivable -> the balance is cleared by an amount that was never
 *                           invoiced, leaving a residual with no document behind it
 *
 * So resolveAccounts() throws, and the settlement cannot be posted until an
 * administrator names the two accounts. That is the one genuinely new configuration
 * step this phase requires, and it is required only by companies that actually
 * transact in a foreign currency - a company that never posts a foreign settlement is
 * never asked for it.
 */
class RealizedFxService
{
    /**
     * Compare what a settlement is worth now against what the balance carried.
     *
     * Both arguments are base-currency amounts. They are passed in already converted
     * rather than being converted here, because the settlement's rate is the
     * receipt's rate and the balance's rate is the invoice's, and each was resolved
     * against its own document's date upstream. Converting here would mean this class
     * needed both documents' dates as well, and would then be the second place those
     * rates are resolved.
     */
    public function compute(Money $settlementBase, Money $carryingBase): RealizedFxResult
    {
        return RealizedFxResult::between($settlementBase, $carryingBase);
    }

    /**
     * The account a realised result posts to.
     *
     * @throws ValidationException when the company has not configured FX accounts
     */
    public function resolveAccount(Company $company, bool $isGain, string $field = 'fx_settings'): Account
    {
        $setting = CompanyFxSetting::query()
            ->where('company_id', $company->getKey())
            ->first();

        if ($setting === null || ! $setting->isConfigured()) {
            throw ValidationException::withMessages([
                $field => self::unconfiguredMessage(),
            ]);
        }

        $account = $setting->accountFor($isGain);

        /*
         * isConfigured() guarantees both columns are set, so a null here would mean a
         * row was corrupted underneath us. Refusing is right: posting a gain to no
         * account and reporting success would be worse than a visible failure.
         */
        if ($account === null) {
            throw ValidationException::withMessages([
                $field => self::unconfiguredMessage(),
            ]);
        }

        return $account;
    }

    /**
     * A message naming what to do, not just what is missing.
     *
     * "Realized FX accounts are not configured" tells a user they have a problem.
     * Naming the settings screen and the two accounts tells them how to finish, which
     * is the difference between a validation error and a dead end.
     */
    private static function unconfiguredMessage(): string
    {
        return 'This company cannot post a foreign currency settlement because its realised FX accounts '
            .'are not configured. Set a realised FX gain account and a realised FX loss account in the '
            .'company FX settings first.';
    }

    /**
     * Has this company settled anything in a currency other than its own?
     *
     * Used by the controls service to report a company that transacts in a foreign
     * currency but has no FX accounts - the one configuration state in this phase
     * that is legal right up until the moment it is needed.
     */
    public function canPostForeignSettlement(Company $company): bool
    {
        $setting = CompanyFxSetting::query()
            ->where('company_id', $company->getKey())
            ->first();

        return $setting !== null && $setting->isConfigured();
    }
}
