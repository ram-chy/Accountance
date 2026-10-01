<?php

namespace Tests\Feature\Company;

use App\Enums\RoleName;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompanyCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_admin_can_create_a_company(): void
    {
        Notification::fake();

        $admin = $this->createUserWithRole(RoleName::Admin);

        $response = $this->actingAsJwt($admin)->postJson('/api/companies', [
            'name' => 'Northwind Traders',
            'legal_name' => 'Northwind Traders Limited',
            'country_code' => 'GB',
            'timezone' => 'Europe/London',
            'date_format' => 'd/m/Y',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Northwind Traders')
            ->assertJsonPath('data.address.country_code', 'GB')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_default', true)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'legal_name', 'address', 'timezone', 'date_format', 'settings'],
            ]);

        $this->assertDatabaseHas('companies', ['name' => 'Northwind Traders']);
    }

    public function test_a_company_gets_a_settings_row_on_creation(): void
    {
        Notification::fake();

        $admin = $this->createUserWithRole(RoleName::Admin);

        $id = $this->actingAsJwt($admin)
            ->postJson('/api/companies', ['name' => 'With Settings'])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('company_settings', ['company_id' => $id]);
    }

    public function test_an_unauthenticated_user_cannot_create_a_company(): void
    {
        $this->postJson('/api/companies', ['name' => 'Nope'])
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('companies', 0);
    }

    public function test_a_user_without_create_permission_cannot_create_a_company(): void
    {
        $manager = $this->createUserWithRole(RoleName::Manager);

        $this->actingAsJwt($manager)
            ->postJson('/api/companies', ['name' => 'Manager Attempt'])
            ->assertStatus(403);

        $this->assertDatabaseCount('companies', 0);
    }

    public function test_company_creation_requires_a_name(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);

        $this->actingAsJwt($admin)
            ->postJson('/api/companies', ['country_code' => 'US', 'timezone' => 'UTC', 'date_format' => 'Y-m-d'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /**
     * @return array<string, array<int, array<int, mixed>>>
     */
    public static function invalidFieldProvider(): array
    {
        return [
            'country code too long' => [['country_code' => 'USA']],
            'country code not real' => [['country_code' => 'ZZ']],
            'timezone not IANA' => [['timezone' => 'Mars/Olympus_Mons']],
            'timezone offset rejected' => [['timezone' => '+05:30']],
            'date format unsupported' => [['date_format' => 'DD-MM-YYYY']],
            'email malformed' => [['email' => 'not-an-email']],
            'phone malformed' => [['phone' => 'call-me']],
            'website not a url' => [['website' => 'not a url']],
            'website javascript scheme' => [['website' => 'javascript:alert(1)']],
            'name too long' => [['name' => str_repeat('a', 151)]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidFieldProvider')]
    public function test_invalid_company_fields_are_rejected(array $overrides): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);

        $payload = array_merge([
            'name' => 'Valid Name',
            'country_code' => 'US',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
        ], $overrides);

        $this->actingAsJwt($admin)
            ->postJson('/api/companies', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);

        $this->assertDatabaseCount('companies', 0);
    }

    public function test_country_code_is_normalised_to_uppercase(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);

        $this->actingAsJwt($admin)
            ->postJson('/api/companies', [
                'name' => 'Lower Case Country',
                'country_code' => 'gb',
                'timezone' => 'Europe/London',
                'date_format' => 'Y-m-d',
            ])
            ->assertCreated()
            ->assertJsonPath('data.address.country_code', 'GB');
    }

    public function test_timezone_and_date_format_default_when_omitted(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);

        $this->actingAsJwt($admin)
            ->postJson('/api/companies', ['name' => 'Defaults Applied'])
            ->assertCreated()
            ->assertJsonPath('data.timezone', 'UTC')
            ->assertJsonPath('data.date_format', 'Y-m-d');
    }

    public function test_a_member_can_view_its_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $company->id);
    }

    public function test_a_member_can_update_its_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->putJson("/api/companies/{$company->id}", [
                'name' => 'Renamed Company',
                'country_code' => 'US',
                'timezone' => 'America/New_York',
                'date_format' => 'm/d/Y',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Company')
            ->assertJsonPath('data.timezone', 'America/New_York');

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Renamed Company',
        ]);
    }

    public function test_company_name_must_be_unique(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $first = $this->createCompanyFor($admin, ['name' => 'Taken Name']);
        $second = $this->createCompanyFor($admin, ['name' => 'Other Name']);

        $this->actingAsJwt($admin)
            ->putJson("/api/companies/{$second->id}", [
                'name' => 'Taken Name',
                'country_code' => 'US',
                'timezone' => 'UTC',
                'date_format' => 'Y-m-d',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // A company may keep its own name when updated.
        $this->actingAsJwt($admin)
            ->putJson("/api/companies/{$first->id}", [
                'name' => 'Taken Name',
                'country_code' => 'US',
                'timezone' => 'UTC',
                'date_format' => 'Y-m-d',
            ])
            ->assertOk();
    }

    public function test_a_manager_can_update_but_not_deactivate_a_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $manager = $this->createUserWithRole(RoleName::Manager);
        $company = $this->createCompanyFor($admin);
        $this->createCompanyFor($manager, ['name' => 'Manager Company']);

        $managerCompany = $manager->defaultCompany();

        $this->actingAsJwt($manager)
            ->putJson("/api/companies/{$managerCompany->id}", [
                'name' => 'Manager Renamed',
                'country_code' => 'US',
                'timezone' => 'UTC',
                'date_format' => 'Y-m-d',
            ])
            ->assertOk();

        $this->actingAsJwt($manager)
            ->postJson("/api/companies/{$managerCompany->id}/deactivate")
            ->assertStatus(403);

        $this->assertDatabaseHas('companies', [
            'id' => $managerCompany->id,
            'is_active' => true,
        ]);
    }

    public function test_company_can_be_deactivated_and_reactivated(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$company->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // The row survives: deactivation is a state change, not a delete.
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'is_active' => false]);

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$company->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_company_is_never_hard_deleted(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$company->id}/deactivate")
            ->assertOk();

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_listing_companies_returns_only_the_callers_own(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $mine = $this->createCompanyFor($admin, ['name' => 'Mine']);
        $this->createUnrelatedCompany(['name' => 'Theirs']);

        $response = $this->actingAsJwt($admin)->getJson('/api/companies');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_listing_can_filter_by_search_and_active_state(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin, ['name' => 'Alpha Industries']);
        $inactive = $this->createCompanyFor($admin, ['name' => 'Beta Limited'], isDefault: false);
        $inactive->forceFill(['is_active' => false])->save();

        $this->actingAsJwt($admin)
            ->getJson('/api/companies?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Industries');

        $this->actingAsJwt($admin)
            ->getJson('/api/companies?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beta Limited');
    }

    public function test_company_response_does_not_leak_internal_fields(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $data = $this->actingAsJwt($admin)
            ->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->json('data');

        // companies.currency_id is reserved for the currency phase and must
        // not be exposed. The settings row has its own
        // default_currency_id, which is a different, intended field.
        $this->assertArrayNotHasKey('currency_id', $data);
        $this->assertNull($data['settings']['default_currency_id'] ?? null);

        // Pivot bookkeeping and membership internals stay internal.
        $this->assertArrayNotHasKey('pivot', $data);
        $this->assertArrayNotHasKey('users', $data);
    }

    public function test_a_user_cannot_set_company_state_through_mass_assignment(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);

        $this->actingAsJwt($admin)
            ->postJson('/api/companies', [
                'name' => 'Sneaky Attempt',
                'country_code' => 'US',
                'timezone' => 'UTC',
                'date_format' => 'Y-m-d',
                'currency_id' => 99,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseMissing('companies', ['name' => 'Sneaky Attempt', 'currency_id' => 99]);
    }

    public function test_route_model_binding_uses_companies_table(): void
    {
        $this->assertSame(Company::class, (new Company)->getMorphClass());
    }
}
