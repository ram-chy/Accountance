<?php

namespace Tests\Feature\Currency;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 14 HTTP surface: currencies, exchange rates, FX settings and controls.
 *
 * The service layer is covered by its own files; this one pins the wiring, the
 * authorization boundary and the two properties that only exist at the HTTP edge:
 *
 *   - A currency is global, so its write endpoints are refused to the Accountant
 *     even though that role can read the list and manage rates.
 *   - An exchange-rate id from another company 404s at the route binding, so a
 *     cross-tenant read is indistinguishable from a missing id.
 *
 * Validation failures are asserted as 422 rather than 500 throughout, because the
 * phase brief singles that out: a business rule surfacing as a server error is a
 * bug, not an error path.
 */
class CurrencyApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->createUserWithRole(RoleName::Admin);
    }

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function an_admin_can_create_a_currency_and_the_code_is_normalised(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/currencies', [
                'code' => 'usd',
                'name' => 'US Dollar',
                'symbol' => '$',
                'decimal_precision' => 2,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'USD')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'name' => 'US Dollar']);
    }

    #[Test]
    public function an_accountant_may_read_currencies_but_not_create_or_change_them(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        Currency::factory()->code('USD')->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/currencies')
            ->assertSuccessful()
            ->assertJsonFragment(['code' => 'USD']);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/currencies', [
                'code' => 'EUR',
                'name' => 'Euro',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_staff_user_cannot_read_currencies(): void
    {
        $user = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/currencies')
            ->assertForbidden();
    }

    #[Test]
    public function an_accountant_can_record_an_exchange_rate_scoped_to_the_active_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $usd = Currency::factory()->code('USD')->create();
        $inr = Currency::factory()->code('INR')->create();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/exchange-rates', [
                'from_currency_id' => $usd->getKey(),
                'to_currency_id' => $inr->getKey(),
                'rate' => '83.2500000000',
                'effective_date' => '2026-04-01',
                'source' => 'RBI',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.from_currency', 'USD')
            ->assertJsonPath('data.to_currency', 'INR')
            ->assertJsonPath('data.effective_date', '2026-04-01');

        $this->assertDatabaseHas('exchange_rates', [
            'company_id' => $company->getKey(),
            'from_currency_id' => $usd->getKey(),
            'to_currency_id' => $inr->getKey(),
            'effective_date' => '2026-04-01',
        ]);
    }

    #[Test]
    public function a_duplicate_rate_for_the_same_pair_and_day_is_a_validation_error_not_a_server_error(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $usd = Currency::factory()->code('USD')->create();
        $inr = Currency::factory()->code('INR')->create();

        $payload = [
            'from_currency_id' => $usd->getKey(),
            'to_currency_id' => $inr->getKey(),
            'rate' => '83.2500000000',
            'effective_date' => '2026-04-01',
        ];

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/exchange-rates', $payload)
            ->assertCreated();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounting/exchange-rates', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['effective_date']);
    }

    #[Test]
    public function an_exchange_rate_from_another_company_is_not_found(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $other = $this->createUnrelatedCompany();
        $foreignRate = ExchangeRate::factory()->for($other)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/exchange-rates/{$foreignRate->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function an_accountant_can_configure_the_realised_fx_account_pair(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $gain = Account::factory()->for($company)->revenue()->create(['code' => '4100', 'name' => 'FX Gain']);
        $loss = Account::factory()->for($company)->expense()->create(['code' => '5100', 'name' => 'FX Loss']);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson('/api/accounting/fx-settings', [
                'realized_gain_account_id' => $gain->getKey(),
                'realized_loss_account_id' => $loss->getKey(),
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.is_configured', true)
            ->assertJsonPath('data.realized_gain_account_id', $gain->getKey())
            ->assertJsonPath('data.realized_loss_account_id', $loss->getKey());

        $this->assertDatabaseHas('company_fx_settings', [
            'company_id' => $company->getKey(),
            'realized_gain_account_id' => $gain->getKey(),
            'realized_loss_account_id' => $loss->getKey(),
        ]);
    }

    #[Test]
    public function the_gain_account_must_be_revenue(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $asset = Account::factory()->for($company)->asset()->create(['code' => '1010', 'name' => 'Cash']);
        $loss = Account::factory()->for($company)->expense()->create(['code' => '5100', 'name' => 'FX Loss']);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson('/api/accounting/fx-settings', [
                'realized_gain_account_id' => $asset->getKey(),
                'realized_loss_account_id' => $loss->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['realized_gain_account_id']);
    }

    #[Test]
    public function the_fx_accounts_must_belong_to_the_active_company(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();
        $foreignGain = Account::factory()->for($other)->revenue()->create(['code' => '4100']);
        $loss = Account::factory()->for($company)->expense()->create(['code' => '5100', 'name' => 'FX Loss']);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson('/api/accounting/fx-settings', [
                'realized_gain_account_id' => $foreignGain->getKey(),
                'realized_loss_account_id' => $loss->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['realized_gain_account_id']);
    }

    #[Test]
    public function an_accountant_can_read_the_control_report(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/controls')
            ->assertSuccessful()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'company_id',
                    'base_currency',
                    'summary' => ['PASS', 'WARNING', 'FAIL'],
                    'findings',
                ],
            ]);
    }

    #[Test]
    public function a_staff_user_cannot_read_the_control_report(): void
    {
        $user = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($user);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/accounting/controls')
            ->assertForbidden();
    }

    #[Test]
    public function the_base_currency_can_be_chosen_when_no_posted_base_history_exists(): void
    {
        $user = $this->admin();
        $company = $this->createCompanyFor($user, ['currency_id' => null]);
        $usd = Currency::factory()->code('USD')->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson('/api/company/settings/base-currency', ['currency_id' => $usd->getKey()])
            ->assertSuccessful()
            ->assertJsonPath('data.base_currency', 'USD');

        $this->assertDatabaseHas('companies', [
            'id' => $company->getKey(),
            'currency_id' => $usd->getKey(),
        ]);
    }

    #[Test]
    public function changing_an_established_base_currency_with_posted_history_is_refused(): void
    {
        $user = $this->admin();
        $idr = Currency::factory()->code('IDR')->create();
        $usd = Currency::factory()->code('USD')->create();

        $company = $this->createCompanyFor($user, ['currency_id' => $idr->getKey()]);

        $journal = Journal::factory()->for($company)->posted()->create();
        JournalLine::query()->forceCreate([
            'journal_id' => $journal->getKey(),
            'account_id' => Account::factory()->for($company)->asset()->create()->getKey(),
            'line_number' => 1,
            'debit' => '100.0000',
            'credit' => '0.0000',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson('/api/company/settings/base-currency', ['currency_id' => $usd->getKey()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['currency_id']);

        $this->assertDatabaseHas('companies', [
            'id' => $company->getKey(),
            'currency_id' => $idr->getKey(),
        ]);
    }
}
