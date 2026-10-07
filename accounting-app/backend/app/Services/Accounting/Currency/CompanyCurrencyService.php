<?php

namespace App\Services\Accounting\Currency;

use App\Enums\AuditAction;
use App\Enums\JournalStatus;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Choosing and changing a company's base (functional) currency.
 *
 * WHY THIS IS NOT A FIELD EDIT
 *
 * Every posted base amount in the ledger - journal_lines.debit/credit - is
 * denominated in companies.currency_id. The amount is a number with no currency
 * column of its own; the company row supplies the meaning. Changing that row
 * therefore does not convert anything, it reinterprets everything: the same
 * journal line that read "100.00 USD" a moment ago reads "100.00 EUR" now,
 * without a single ledger row moving.
 *
 * That is the single most damaging thing a multi-currency system can do to an
 * accounting record, so this service refuses it whenever posted accounting data
 * exists that the change would silently reinterpret (the phase brief's §25). It
 * never rewrites a journal line and never converts history to the new base.
 *
 * WHAT IS SAFE
 *
 *  - Choosing a base currency for the first time (null -> X) is the documented
 *    setup step. A company with no base books entirely at an implicit identity
 *    rate, so its already-posted amounts are numeric values that only gain a
 *    name; nothing is revalued. It is also the only way a company ever becomes
 *    able to raise a foreign-currency document at all, because every rate
 *    resolution refuses to run without a base.
 *  - Changing the base of a company with no posted accounting data at all is
 *    safe, because there is no history to reinterpret.
 *
 * WHAT IS NOT SAFE
 *
 *  - Changing an established base (X -> Y) once anything has been posted, and
 *  - clearing the base (X -> null) once anything has been posted,
 *  - and, as a hard rule, any change at all once a foreign-currency line has
 *    been posted (its foreign amount was converted at a rate that referenced
 *    the old base, so the stored base amount is now unpriced).
 *
 * The last case cannot arise while the other two hold, but it is checked first
 * and unconditionally so that the rule survives a future relaxation of the
 * others.
 */
class CompanyCurrencyService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly CurrencyService $currencies,
    ) {}

    /**
     * Set the company's base currency, or refuse when doing so is unsafe.
     *
     * @throws ValidationException when the currency is unusable or the change
     *                             would reinterpret posted accounting data.
     */
    public function changeBaseCurrency(Company $company, Currency $currency, ?User $actor = null): Company
    {
        if (! $this->currencies->isUsableAsBaseCurrency($currency)) {
            throw ValidationException::withMessages([
                'currency_id' => 'Currency ['.$currency->code.'] exists but is inactive.',
            ]);
        }

        // Re-selecting the currency already in force is a no-op, not an error:
        // the caller asked for a state the company is already in. It is not
        // audited because nothing changed.
        if ($company->currency_id === $currency->id) {
            return $company;
        }

        $this->assertChangeIsSafe($company, $currency);

        $previousCurrencyId = $company->currency_id;

        return DB::transaction(function () use ($company, $currency, $actor, $previousCurrencyId) {
            $company->currency_id = $currency->id;
            $company->save();

            $this->audit->lifecycle(
                AuditAction::Updated,
                $company,
                $actor,
                ['currency_id' => $previousCurrencyId],
                ['currency_id' => $currency->id],
            );

            return $company->refresh();
        });
    }

    /**
     * Refuse a change that would reinterpret posted accounting data.
     *
     * @throws ValidationException
     */
    private function assertChangeIsSafe(Company $company, Currency $currency): void
    {
        // A posted foreign-currency line stores a base amount that was derived
        // from a rate relative to the old base. No later base is compatible with
        // it, whether or not the chosen currency differs, so this is refused
        // first and on its own.
        if ($this->hasPostedForeignLines($company)) {
            throw ValidationException::withMessages([
                'currency_id' => 'The base currency cannot be changed because the company has posted '
                    .'foreign-currency entries, whose stored base amounts are priced against the current '
                    .'base. Reverse or re-post the affected journals first.',
            ]);
        }

        // Assigning a base currency to a company that never had one does not
        // revalue anything: its posted amounts were booked at an implicit
        // identity rate and merely acquire a name. This is the documented path
        // by which a company becomes able to transact in foreign currencies.
        if ($company->currency_id === null) {
            return;
        }

        // Changing or clearing an established base reinterprets every posted
        // base amount in the ledger, so any posted accounting data at all makes
        // it unsafe.
        if ($this->hasPostedAccounting($company)) {
            throw ValidationException::withMessages([
                'currency_id' => 'The base currency cannot be changed because the company has posted '
                    .'accounting data denominated in its current base. A base-currency change would '
                    .'silently reinterpret those balances. It is refused once anything has been posted.',
            ]);
        }
    }

    /**
     * Whether the company has any posted journal, i.e. any posted accounting
     * data whose base amounts the current base currency names.
     *
     * Public because the accounting-control layer reports the same fact to a
     * human; the rule should have one implementation, not two that can drift.
     */
    public function hasPostedAccounting(Company $company): bool
    {
        return Journal::query()
            ->where('company_id', $company->id)
            ->where('status', JournalStatus::Posted->value)
            ->exists();
    }

    /**
     * Whether the company has posted any journal line transacted in a currency
     * other than its base.
     *
     * journal_lines carries no company_id of its own - that would be a second
     * source of truth the schema deliberately avoids - so the company scope is
     * reached through the owning journal.
     *
     * Public for the same reason as hasPostedAccounting(): the control layer
     * reports this state without re-deriving it.
     */
    public function hasPostedForeignLines(Company $company): bool
    {
        return JournalLine::query()
            ->whereNotNull('currency_id')
            ->whereHas('journal', function ($query) use ($company) {
                $query->where('company_id', $company->id)
                    ->where('status', JournalStatus::Posted->value);
            })
            ->exists();
    }
}
