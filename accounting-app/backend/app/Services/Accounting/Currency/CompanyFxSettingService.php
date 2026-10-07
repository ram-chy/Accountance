<?php

namespace App\Services\Accounting\Currency;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyFxSetting;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The company's destination accounts for realised FX gains and losses.
 *
 * A one-to-one companion to Company, always resolved for the active company and
 * never from a client-supplied id. The row may be missing on a company that has
 * never configured FX; `for()` creates it on demand, so callers never handle the
 * missing-row case.
 *
 * WHY THE VALIDATION IS HERE AND NOT IN THE REQUEST
 *
 * Two of the rules need the resolved accounts, not the raw ids: each account must
 * belong to the same company as the setting, and the gain account must be income
 * while the loss account is an expense. A `Rule::exists` cannot express "belongs to
 * this company" without a closure that duplicates the lookups, and it cannot check
 * account type at all. Doing it here also protects a console command or a factory
 * caller, not just the HTTP path.
 */
class CompanyFxSettingService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Fetch the company's FX settings row, creating an empty one if missing.
     *
     * The create is idempotent; the unique index on company_id is the authority if
     * two requests race, exactly as CompanySettingsService::for() documents.
     */
    public function for(Company $company): CompanyFxSetting
    {
        $setting = $company->fxSettings()->first();

        if ($setting) {
            return $setting;
        }

        try {
            /*
             * Created through the relation, not `CompanyFxSetting::create()`, and
             * that is not a style choice: company_id is not mass-assignable on the
             * model, so a plain create would be refused. The relation sets the
             * foreign key itself and is the same shape CompanySettingsService::for()
             * uses for its own singleton.
             */
            return $company->fxSettings()->create();
        } catch (QueryException) {
            return $company->fxSettings()->firstOrFail();
        }
    }

    /**
     * Set, replace or clear the realised FX account pair.
     *
     * Full-replacement semantics: the two fields are always taken together, and
     * sending both as null clears the configuration. A half-set pair is refused here
     * and would also be refused by the database's paired-nonnull CHECK.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function update(Company $company, array $values, ?User $actor = null): CompanyFxSetting
    {
        $gain = $this->accountFor($company, $values['realized_gain_account_id'] ?? null, 'realized_gain_account_id');
        $loss = $this->accountFor($company, $values['realized_loss_account_id'] ?? null, 'realized_loss_account_id');

        if ($gain !== null && $gain->account_type !== AccountType::Revenue) {
            throw ValidationException::withMessages([
                'realized_gain_account_id' => 'A realised FX gain must post to a revenue account.',
            ]);
        }

        if ($loss !== null && $loss->account_type !== AccountType::Expense) {
            throw ValidationException::withMessages([
                'realized_loss_account_id' => 'A realised FX loss must post to an expense account.',
            ]);
        }

        if (($gain === null) !== ($loss === null)) {
            throw ValidationException::withMessages([
                $gain === null ? 'realized_gain_account_id' : 'realized_loss_account_id' => 'A realised FX gain account and a realised FX loss account must be configured together, or both left empty.',
            ]);
        }

        $setting = $this->for($company);

        $setting->forceFill([
            'realized_gain_account_id' => $gain?->getKey(),
            'realized_loss_account_id' => $loss?->getKey(),
        ]);

        DB::transaction(function () use ($setting, $actor): void {
            $setting->save();

            $this->audit->updated($setting, $actor);
        });

        return $setting->refresh();
    }

    /**
     * Resolve an account id to a company-owned account, or null when unset.
     *
     * @throws ValidationException when the account does not belong to the company
     */
    private function accountFor(Company $company, mixed $accountId, string $field): ?Account
    {
        if ($accountId === null || $accountId === '') {
            return null;
        }

        $account = Account::query()
            ->where('company_id', $company->getKey())
            ->whereKey($accountId)
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                $field => 'The selected account does not belong to this company.',
            ]);
        }

        return $account;
    }
}
