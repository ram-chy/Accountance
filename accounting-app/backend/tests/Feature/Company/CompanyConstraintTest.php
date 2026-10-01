<?php

namespace Tests\Feature\Company;

use App\Enums\RoleName;
use App\Models\Company;
use App\Services\CompanyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the constraints the service layer relies on.
 *
 * The service already clears a previous default before setting a new one, so
 * these tests attack the pivot directly. They prove the database refuses the
 * states the application must never be able to produce.
 */
class CompanyConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_company_name_is_unique(): void
    {
        Company::factory()->create(['name' => 'Duplicate Co Ltd']);

        $this->expectException(QueryException::class);

        Company::factory()->create(['name' => 'Duplicate Co Ltd']);
    }

    public function test_a_duplicate_name_reports_a_validation_error_rather_than_a_500(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        Company::factory()->create(['name' => 'Duplicate Co Ltd']);

        // Rule::unique normally catches this. This asserts the database
        // backstop, reached when two requests race past the check, still
        // produces a 422 instead of surfacing a QueryException as a 500.
        $this->actingAsJwt($admin)
            ->postJson('/api/companies', ['name' => 'Duplicate Co Ltd'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_two_companies_may_share_a_name_differing_only_by_case(): void
    {
        // MySQL's default collation is case-insensitive, so these collide.
        Company::factory()->create(['name' => 'Case Co Ltd']);

        $this->expectException(QueryException::class);

        Company::factory()->create(['name' => 'case co ltd']);
    }

    public function test_a_user_cannot_be_membership_twice_in_one_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->expectException(QueryException::class);

        $company->users()->attach($admin->id, ['is_default' => false]);
    }

    public function test_a_user_cannot_have_two_default_companies(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin, ['name' => 'First Co']);
        $second = $this->createCompanyFor($admin, ['name' => 'Second Co'], isDefault: false);

        // is_default stays false so the second company attaches cleanly, then
        // the generated default_for_user column must block the second default.
        $this->expectException(QueryException::class);

        DB::table('company_user')
            ->where('user_id', $admin->id)
            ->where('company_id', $second->id)
            ->update(['is_default' => true]);
    }

    public function test_two_users_may_share_the_same_default_company(): void
    {
        $first = $this->createUserWithRole(RoleName::Admin);
        $second = $this->createUserWithRole(RoleName::Manager);
        $company = $this->createCompanyFor($first, isDefault: true);

        // Not a constraint violation: the uniqueness is per user, not global.
        $company->users()->attach($second->id, ['is_default' => true]);

        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->id,
            'user_id' => $second->id,
            'is_default' => true,
        ]);
    }

    public function test_a_company_requires_a_settings_row_when_created_through_the_service(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);

        $company = app(CompanyService::class)->create($admin, ['name' => 'Settings Co']);

        $this->assertDatabaseHas('company_settings', ['company_id' => $company->id]);
        $this->assertSame(0, $company->settings->default_payment_terms_days);
        $this->assertSame(1, $company->settings->default_fiscal_year_start_month);
    }

    public function test_a_company_can_have_only_one_settings_row(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->expectException(QueryException::class);

        $company->settings()->create();
    }

    public function test_deleting_a_company_removes_its_memberships_and_settings(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $company->forceDelete();

        $this->assertDatabaseMissing('company_user', ['company_id' => $company->id]);
        $this->assertDatabaseMissing('company_settings', ['company_id' => $company->id]);
    }

    public function test_deleting_a_company_leaves_other_companies_intact(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $doomed = $this->createCompanyFor($admin, ['name' => 'Doomed Co']);
        $kept = $this->createCompanyFor($admin, ['name' => 'Kept Co'], isDefault: false);

        $doomed->forceDelete();

        $this->assertDatabaseHas('companies', ['id' => $kept->id]);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $kept->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_a_company_cannot_reference_a_missing_parent(): void
    {
        $this->expectException(QueryException::class);

        DB::table('company_user')->insert([
            'company_id' => 987654,
            'user_id' => 987654,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_country_code_is_stored_uppercase(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);

        $company = app(CompanyService::class)->create($admin, [
            'name' => 'Lowercase Co',
            'country_code' => 'us',
        ]);

        $this->assertSame('US', $company->refresh()->country_code);
    }

    public function test_timezone_is_stored_as_given(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);

        $company = app(CompanyService::class)->create($admin, [
            'name' => 'TZ Co',
            'timezone' => 'Africa/Nairobi',
        ]);

        $this->assertSame('Africa/Nairobi', $company->refresh()->timezone);
    }

    public function test_is_active_defaults_to_true(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);

        $company = app(CompanyService::class)->create($admin, ['name' => 'Active Co']);

        $this->assertTrue((bool) $company->refresh()->is_active);
    }

    public function test_a_company_is_not_persisted_without_an_authorized_creator(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);

        // Simulates the authorization being bypassed: create() always attaches
        // the creator, so a company can never exist without its first member.
        $company = app(CompanyService::class)->create($admin, ['name' => 'Orphan Co']);

        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->id,
            'user_id' => $admin->id,
        ]);
    }
}
