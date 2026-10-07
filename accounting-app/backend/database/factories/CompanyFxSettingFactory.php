<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyFxSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyFxSetting>
 */
class CompanyFxSettingFactory extends Factory
{
    protected $model = CompanyFxSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),

            /*
             * Null by default, which is the honest starting state: a company has not
             * been told which accounts to book FX results through, and the schema's
             * paired-nonnull CHECK enforces that neither is set without the other.
             *
             * A factory that invented gain and loss accounts would mean every test
             * touching a settlement had an FX configuration it did not choose, and
             * the "what happens when a company has not configured FX accounts"
             * question - which is the question most worth asking - would need a
             * bespoke override to reach at all.
             */
            'realized_gain_account_id' => null,
            'realized_loss_account_id' => null,
        ];
    }

    /**
     * Configured, with real accounts of the right types.
     *
     * `configured()` is the state almost every FX test wants, and naming it as a
     * state rather than leaving it to inline overrides means the accounts are always
     * a genuine income and expense pair - an FX result posted to a revenue account
     * would balance and still be wrong, and nothing downstream would object.
     */
    public function configured(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->getKey(),
            'realized_gain_account_id' => Account::factory()
                ->for($company)
                ->revenue()
                ->create([
                    'code' => '4900',
                    'name' => 'Realised FX Gain',
                ])
                ->getKey(),
            'realized_loss_account_id' => Account::factory()
                ->for($company)
                ->expense()
                ->create([
                    'code' => '5900',
                    'name' => 'Realised FX Loss',
                ])
                ->getKey(),
        ]);
    }

    /**
     * The half-configured state the schema's CHECK forbids.
     *
     * Exposed so a test can assert the constraint rejects it, in the same way
     * assertDatabaseIntegrityViolation exists for the other storage-layer rules -
     * rather than leaving the pair untested because no legitimate code path produces
     * it.
     */
    public function gainOnly(Account $gainAccount): static
    {
        return $this->state(fn () => [
            'realized_gain_account_id' => $gainAccount->getKey(),
            'realized_loss_account_id' => null,
        ]);
    }
}
