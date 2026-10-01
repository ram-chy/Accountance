<?php

namespace Tests\Feature\Company;

use App\Enums\RoleName;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_company_resolves_without_a_header(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->getJson('/api/company')
            ->assertOk()
            ->assertJsonPath('data.id', $company->id);
    }

    public function test_the_header_selects_a_different_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin, ['name' => 'Default Co']);
        $second = $this->createCompanyFor($admin, ['name' => 'Second Co'], isDefault: false);

        $this->actingAsJwt($admin)
            ->withCompanyContext($second)
            ->getJson('/api/company')
            ->assertOk()
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.name', 'Second Co');
    }

    public function test_an_unrelated_company_is_rejected(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);
        $notMine = $this->createUnrelatedCompany();

        $this->actingAsJwt($admin)
            ->withCompanyContext($notMine)
            ->getJson('/api/company')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_an_inactive_company_is_rejected(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);
        $inactive = $this->createCompanyFor($admin, ['is_active' => false], isDefault: false);

        $this->actingAsJwt($admin)
            ->withCompanyContext($inactive)
            ->getJson('/api/company')
            ->assertStatus(403);
    }

    public function test_an_unknown_company_returns_404(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->withHeader(CompanyContext::HEADER, '999999')
            ->getJson('/api/company')
            ->assertStatus(404);
    }

    public function test_a_user_with_no_company_is_refused(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);

        $this->actingAsJwt($admin)
            ->getJson('/api/company')
            ->assertStatus(403);
    }

    public function test_an_unauthenticated_user_cannot_resolve_context(): void
    {
        $this->getJson('/api/company')
            ->assertStatus(401);
    }

    public function test_switching_sets_the_default_and_echoes_the_header(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin, ['name' => 'First Co']);
        $second = $this->createCompanyFor($admin, ['name' => 'Second Co'], isDefault: false);

        $response = $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$second->id}/switch");

        $response->assertOk()
            ->assertJsonPath('data.id', $second->id)
            ->assertHeader(CompanyContext::HEADER, (string) $second->id);

        $this->assertEquals($second->id, $admin->refresh()->defaultCompany()?->id);
    }

    public function test_switching_to_an_unrelated_company_is_forbidden(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $original = $this->createCompanyFor($admin);
        $notMine = $this->createUnrelatedCompany();

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$notMine->id}/switch")
            ->assertStatus(403);

        $this->assertEquals($original->id, $admin->refresh()->defaultCompany()?->id);
    }

    public function test_switching_to_an_inactive_company_is_forbidden(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);
        $inactive = $this->createCompanyFor($admin, ['is_active' => false], isDefault: false);

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$inactive->id}/switch")
            ->assertStatus(403);
    }

    public function test_switching_does_not_affect_another_user(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $other = $this->createUserWithRole(RoleName::Staff);

        $shared = $this->createCompanyFor($admin, ['name' => 'Shared Co'], isDefault: false);
        $otherCompany = $this->createCompanyFor($other, ['name' => 'Other Co']);

        // Both users belong to the shared company, but only the admin is
        // switching, so the other user's own default must not move.
        $other->companies()->attach($shared->id, ['is_default' => false]);

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$shared->id}/switch")
            ->assertOk();

        $this->assertEquals($shared->id, $admin->refresh()->defaultCompany()?->id);
        $this->assertEquals($otherCompany->id, $other->refresh()->defaultCompany()?->id);
    }

    public function test_any_member_may_switch_regardless_of_role(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $staff = $this->createUserWithRole(RoleName::Staff);

        $shared = $this->createCompanyFor($admin, ['name' => 'Shared Co'], isDefault: false);
        $staff->companies()->attach($shared->id, ['is_default' => true]);

        // Staff cannot create companies...
        $this->actingAsJwt($staff)
            ->postJson('/api/companies', ['name' => 'Staff Attempt'])
            ->assertStatus(403);

        // ...but can select a company it belongs to.
        $this->actingAsJwt($staff)
            ->postJson("/api/companies/{$shared->id}/switch")
            ->assertOk()
            ->assertJsonPath('data.id', $shared->id);
    }

    public function test_deactivating_a_default_moves_the_default_safely(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $first = $this->createCompanyFor($admin, ['name' => 'First Co']);
        $second = $this->createCompanyFor($admin, ['name' => 'Second Co']);

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$second->id}/deactivate")
            ->assertOk();

        // First was the default, second is now inactive, so the default must
        // have moved to the only remaining active company.
        $this->assertEquals(
            $first->id,
            $admin->refresh()->defaultCompany()?->id,
        );
    }

    public function test_deactivating_the_only_company_clears_the_default(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $only = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$only->id}/deactivate")
            ->assertOk();

        $admin->refresh();

        $this->assertNull($admin->defaultCompany());

        // And the context endpoint reports the problem rather than 500ing.
        $this->actingAsJwt($admin)
            ->getJson('/api/company')
            ->assertStatus(403);
    }

    public function test_a_reactivated_company_does_not_silently_become_default(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $only = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)->postJson("/api/companies/{$only->id}/deactivate");
        $this->actingAsJwt($admin)->postJson("/api/companies/{$only->id}/activate");

        $this->assertNull($admin->refresh()->defaultCompany());
    }

    public function test_context_is_not_shared_between_requests(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin, ['name' => 'First Co']);
        $second = $this->createCompanyFor($admin, ['name' => 'Second Co'], isDefault: false);

        $this->actingAsJwt($admin)->withCompanyContext($second)->getJson('/api/company')
            ->assertJsonPath('data.id', $second->id);

        // withHeader() writes to the test's persistent default header bag, so
        // the header must be removed explicitly to simulate a fresh client.
        // Falling back to the default again proves no context leaked between
        // requests through the scoped service.
        $this->withoutHeader(CompanyContext::HEADER);

        $this->actingAsJwt($admin)->getJson('/api/company')
            ->assertJsonPath('data.name', 'First Co');
    }

    public function test_a_malformed_header_falls_back_to_the_default(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->withHeader(CompanyContext::HEADER, 'not-a-number')
            ->getJson('/api/company')
            ->assertOk()
            ->assertJsonPath('data.id', $company->id);
    }

    public function test_an_inactive_default_is_not_returned_by_default_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);
        $company->forceFill(['is_active' => false])->save();

        $this->assertNull($admin->refresh()->defaultCompany());
    }

    public function test_company_context_resolves_a_member_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->withCompanyContext($company)
            ->getJson('/api/company')
            ->assertOk()
            ->assertJsonPath('data.id', $company->id);
    }

    public function test_switch_endpoint_does_not_issue_a_new_token(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $body = $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$company->id}/switch")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('token', $body);
    }
}
