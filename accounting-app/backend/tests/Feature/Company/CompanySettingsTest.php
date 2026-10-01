<?php

namespace Tests\Feature\Company;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_settings_are_returned_as_a_typed_object(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);

        $response = $this->actingAsJwt($admin)->getJson('/api/company/settings');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'company_id',
                    'invoice_number_prefix',
                    'quotation_number_prefix',
                    'default_payment_terms_days',
                    'default_currency_id',
                    'default_fiscal_year_start_month',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertIsInt($response->json('data.default_payment_terms_days'));
        $this->assertIsInt($response->json('data.default_fiscal_year_start_month'));
    }

    public function test_settings_can_be_updated_and_read_back(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);

        $payload = [
            'invoice_number_prefix' => 'INV-',
            'quotation_number_prefix' => 'QUO-',
            'default_payment_terms_days' => 30,
            'default_currency_id' => 12,
            'default_fiscal_year_start_month' => 4,
        ];

        $this->actingAsJwt($admin)
            ->putJson('/api/company/settings', $payload)
            ->assertOk()
            ->assertJsonPath('data.invoice_number_prefix', 'INV-')
            ->assertJsonPath('data.quotation_number_prefix', 'QUO-')
            ->assertJsonPath('data.default_payment_terms_days', 30)
            ->assertJsonPath('data.default_currency_id', 12)
            ->assertJsonPath('data.default_fiscal_year_start_month', 4);

        $this->actingAsJwt($admin)
            ->getJson('/api/company/settings')
            ->assertJsonPath('data.invoice_number_prefix', 'INV-')
            ->assertJsonPath('data.default_currency_id', 12);
    }

    public function test_settings_are_scoped_to_the_active_company_only(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $first = $this->createCompanyFor($admin, ['name' => 'First Co']);
        $second = $this->createCompanyFor($admin, ['name' => 'Second Co'], isDefault: false);

        $this->actingAsJwt($admin)
            ->withCompanyContext($first)
            ->putJson('/api/company/settings', ['invoice_number_prefix' => 'A-']);

        $this->actingAsJwt($admin)
            ->withCompanyContext($second)
            ->putJson('/api/company/settings', ['invoice_number_prefix' => 'B-']);

        $this->actingAsJwt($admin)->withCompanyContext($first)
            ->getJson('/api/company/settings')
            ->assertJsonPath('data.invoice_number_prefix', 'A-');

        $this->actingAsJwt($admin)->withCompanyContext($second)
            ->getJson('/api/company/settings')
            ->assertJsonPath('data.invoice_number_prefix', 'B-');
    }

    public function test_settings_cannot_be_written_into_another_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $mine = $this->createCompanyFor($admin);
        $notMine = $this->createUnrelatedCompany();
        $notMine->settings()->create(['invoice_number_prefix' => 'THEIRS-']);

        // A forged company_id in the body must not redirect the write: the
        // endpoint takes the company from the context, never from the payload.
        $this->actingAsJwt($admin)
            ->withCompanyContext($mine)
            ->putJson('/api/company/settings', [
                'invoice_number_prefix' => 'MINE-',
                'company_id' => $notMine->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.company_id', $mine->id);

        $this->assertSame('MINE-', $mine->refresh()->settings->invoice_number_prefix);
        $this->assertSame('THEIRS-', $notMine->refresh()->settings->invoice_number_prefix);
    }

    public function test_a_non_member_cannot_read_settings(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);
        $notMine = $this->createUnrelatedCompany();

        $this->actingAsJwt($admin)
            ->withCompanyContext($notMine)
            ->getJson('/api/company/settings')
            ->assertStatus(403);
    }

    public function test_a_manager_can_update_settings(): void
    {
        $manager = $this->createUserWithRole(RoleName::Manager);
        $this->createCompanyFor($manager);

        $this->actingAsJwt($manager)
            ->putJson('/api/company/settings', ['default_payment_terms_days' => 14])
            ->assertOk()
            ->assertJsonPath('data.default_payment_terms_days', 14);
    }

    public function test_an_accountant_cannot_read_or_update_settings(): void
    {
        $accountant = $this->createUserWithRole(RoleName::Accountant);
        $this->createCompanyFor($accountant);

        // config/authorization.php grants settings rights to Admin and Manager
        // only; Accountant keeps users.view and companies.view and nothing more.
        $this->actingAsJwt($accountant)
            ->getJson('/api/company/settings')
            ->assertStatus(403);

        $this->actingAsJwt($accountant)
            ->putJson('/api/company/settings', ['default_payment_terms_days' => 14])
            ->assertStatus(403);
    }

    public function test_a_staff_user_cannot_read_or_write_settings(): void
    {
        $staff = $this->createUserWithRole(RoleName::Staff);
        $this->createCompanyFor($staff);

        $this->actingAsJwt($staff)
            ->getJson('/api/company/settings')
            ->assertStatus(403);

        $this->actingAsJwt($staff)
            ->putJson('/api/company/settings', ['default_payment_terms_days' => 7])
            ->assertStatus(403);
    }

    public function test_an_admin_not_authorized_for_another_company_cannot_touch_its_settings(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $otherAdmin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);
        $theirs = $this->createCompanyFor($otherAdmin);
        $theirs->settings()->update(['invoice_number_prefix' => 'THEIRS-']);

        // Admin holds every permission, so only the membership check stands
        // between one admin and another admin's company settings.
        $this->actingAsJwt($admin)
            ->withCompanyContext($theirs)
            ->putJson('/api/company/settings', ['invoice_number_prefix' => 'MINE-NOW-'])
            ->assertStatus(403);

        $this->assertSame('THEIRS-', $theirs->refresh()->settings->invoice_number_prefix);
    }

    public function test_the_fiscal_year_start_month_must_be_within_the_year(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->putJson('/api/company/settings', ['default_fiscal_year_start_month' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_fiscal_year_start_month');

        $this->actingAsJwt($admin)
            ->putJson('/api/company/settings', ['default_fiscal_year_start_month' => 13])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_fiscal_year_start_month');
    }

    public function test_payment_terms_cannot_be_negative(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->putJson('/api/company/settings', ['default_payment_terms_days' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_payment_terms_days');
    }

    public function test_payment_terms_have_a_sane_upper_bound(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->putJson('/api/company/settings', ['default_payment_terms_days' => 100000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_payment_terms_days');
    }

    public function test_prefixes_are_length_limited(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->putJson('/api/company/settings', [
                'invoice_number_prefix' => str_repeat('X', 21),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_number_prefix');
    }

    public function test_omitted_fields_keep_their_existing_value(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)->putJson('/api/company/settings', [
            'invoice_number_prefix' => 'KEEP-',
            'default_payment_terms_days' => 45,
        ])->assertOk();

        $this->actingAsJwt($admin)->putJson('/api/company/settings', [
            'quotation_number_prefix' => 'NEW-',
        ])->assertOk()->assertJsonPath('data.invoice_number_prefix', 'KEEP-')
            ->assertJsonPath('data.default_payment_terms_days', 45)
            ->assertJsonPath('data.quotation_number_prefix', 'NEW-');
    }

    public function test_the_company_id_in_the_response_is_the_active_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $first = $this->createCompanyFor($admin, ['name' => 'First Co']);
        $second = $this->createCompanyFor($admin, ['name' => 'Second Co'], isDefault: false);

        $this->actingAsJwt($admin)->withCompanyContext($second)
            ->getJson('/api/company/settings')
            ->assertJsonPath('data.company_id', $second->id)
            ->assertJsonPath('data.company_id', fn ($id) => $id !== $first->id);
    }

    public function test_settings_require_authentication(): void
    {
        $this->getJson('/api/company/settings')->assertStatus(401);
        $this->putJson('/api/company/settings', ['invoice_number_prefix' => 'X-'])
            ->assertStatus(401);
    }

    public function test_settings_are_rejected_for_a_user_with_no_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);

        $this->actingAsJwt($admin)
            ->getJson('/api/company/settings')
            ->assertStatus(403);

        $this->actingAsJwt($admin)
            ->putJson('/api/company/settings', ['invoice_number_prefix' => 'X-'])
            ->assertStatus(403);
    }

    public function test_the_company_list_embeds_settings_for_each_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $first = $this->createCompanyFor($admin, ['name' => 'First Co']);
        $second = $this->createCompanyFor($admin, ['name' => 'Second Co'], isDefault: false);

        $this->actingAsJwt($admin)
            ->withCompanyContext($first)
            ->putJson('/api/company/settings', ['invoice_number_prefix' => 'A-']);

        $this->actingAsJwt($admin)
            ->withCompanyContext($second)
            ->putJson('/api/company/settings', ['invoice_number_prefix' => 'B-']);

        $response = $this->actingAsJwt($admin)->getJson('/api/companies')->assertOk();

        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertSame('A-', $byId[$first->id]['settings']['invoice_number_prefix']);
        $this->assertSame('B-', $byId[$second->id]['settings']['invoice_number_prefix']);
    }
}
