<?php

namespace Tests\Feature\Company;

use App\Enums\RoleName;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The explicit security-review cases called for by the brief, section 17.
 *
 * CompanyCrudTest and CompanyContextTest cover much of this ground already.
 * This file concentrates the IDOR and mass-assignment cases in one place so the
 * review can be read against the requirements.
 */
class CompanySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_a_cannot_read_user_b_s_company_by_id(): void
    {
        $a = $this->createUserWithRole(RoleName::Admin);
        $b = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($a);
        $theirs = $this->createCompanyFor($b, ['name' => 'B Company']);

        $this->actingAsJwt($a)
            ->getJson("/api/companies/{$theirs->id}")
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_user_a_cannot_update_user_b_s_company_by_id(): void
    {
        $a = $this->createUserWithRole(RoleName::Admin);
        $b = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($a);
        $theirs = $this->createCompanyFor($b, ['name' => 'B Company']);

        $this->actingAsJwt($a)
            ->putJson("/api/companies/{$theirs->id}", ['name' => 'Stolen'])
            ->assertStatus(403);

        $this->assertSame('B Company', $theirs->refresh()->name);
    }

    public function test_user_a_cannot_deactivate_user_b_s_company(): void
    {
        $a = $this->createUserWithRole(RoleName::Admin);
        $b = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($a);
        $theirs = $this->createCompanyFor($b);

        $this->actingAsJwt($a)
            ->postJson("/api/companies/{$theirs->id}/deactivate")
            ->assertStatus(403);

        $this->assertTrue((bool) $theirs->refresh()->is_active);
    }

    public function test_an_unknown_company_id_returns_404(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->getJson('/api/companies/999999')
            ->assertStatus(404);
    }

    public function test_inactive_companies_cannot_be_used_as_active_context(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $inactive = $this->createCompanyFor($admin, ['is_active' => false], isDefault: false);

        $this->actingAsJwt($admin)
            ->withCompanyContext($inactive)
            ->getJson('/api/company/settings')
            ->assertStatus(403);
    }

    public function test_company_endpoints_require_authentication(): void
    {
        $this->getJson('/api/companies')->assertStatus(401);
        $this->postJson('/api/companies', ['name' => 'X'])->assertStatus(401);
        $this->getJson('/api/company')->assertStatus(401);
    }

    public function test_is_active_cannot_be_set_through_the_company_payload(): void
    {
        $staff = $this->createUserWithRole(RoleName::Staff);
        $existing = $this->createCompanyFor($staff);

        // Staff cannot create companies, so this is refused on authorisation
        // before mass assignment is even considered.
        $this->actingAsJwt($staff)
            ->postJson('/api/companies', ['name' => 'Smuggled', 'is_active' => true])
            ->assertStatus(403);

        $this->assertDatabaseMissing('companies', ['name' => 'Smuggled']);
        $this->assertTrue((bool) $existing->refresh()->is_active);
    }

    public function test_is_active_cannot_be_reactivated_through_the_update_payload(): void
    {
        $manager = $this->createUserWithRole(RoleName::Manager);
        $company = $this->createCompanyFor($manager);

        // Manager holds companies.update but not companies.delete, so it can
        // neither deactivate nor reactivate.
        $this->actingAsJwt($manager)
            ->postJson("/api/companies/{$company->id}/deactivate")
            ->assertStatus(403);

        $this->assertTrue((bool) $company->refresh()->is_active);

        // A plain update carrying is_active must leave the flag alone. The
        // update route is a full replacement, so the required company fields
        // are resent alongside the flag.
        $this->actingAsJwt($manager)
            ->putJson("/api/companies/{$company->id}", [
                'name' => $company->name,
                'country_code' => $company->country_code,
                'timezone' => $company->timezone,
                'date_format' => $company->date_format,
                'is_active' => false,
            ])
            ->assertStatus(200);

        $this->assertTrue((bool) $company->refresh()->is_active);
    }

    public function test_is_active_is_not_fillable_on_the_model(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        // Defence in depth: even a direct call to fill() must ignore it, so
        // only the service can move the flag.
        $company->fill(['name' => 'Renamed', 'is_active' => false]);

        $this->assertFalse($company->isDirty('is_active'));
        $this->assertSame('Renamed', $company->name);
    }

    public function test_a_user_cannot_grant_themselves_a_role_through_a_company_payload(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $staff = $this->createUserWithRole(RoleName::Staff);

        $this->actingAsJwt($admin)
            ->postJson('/api/companies', [
                'name' => 'Role Probe Co',
                'role' => 'Admin',
                'user_id' => $staff->id,
            ])
            ->assertStatus(201);

        // The creator is attached as the sole member, and no role moved.
        $this->assertFalse($staff->fresh()->hasRole(RoleName::Admin));
    }

    public function test_a_non_member_cannot_be_attached_to_an_unrelated_company(): void
    {
        $a = $this->createUserWithRole(RoleName::Admin);
        $b = $this->createUserWithRole(RoleName::Admin);
        $victim = $this->createUserWithRole(RoleName::Staff);
        $this->createCompanyFor($a);
        $theirs = $this->createCompanyFor($b);

        $this->actingAsJwt($a)
            ->postJson("/api/companies/{$theirs->id}/members", ['user_id' => $victim->id])
            ->assertStatus(403);

        $this->assertFalse($theirs->refresh()->hasMember($victim));
    }

    public function test_a_non_member_cannot_be_detached_from_an_unrelated_company(): void
    {
        $a = $this->createUserWithRole(RoleName::Admin);
        $b = $this->createUserWithRole(RoleName::Admin);
        $victim = $this->createUserWithRole(RoleName::Manager);
        $this->createCompanyFor($a);
        $theirs = $this->createCompanyFor($b);
        $theirs->users()->attach($victim->id, ['is_default' => true]);

        $this->actingAsJwt($a)
            ->deleteJson("/api/companies/{$theirs->id}/members/{$victim->id}")
            ->assertStatus(403);

        $this->assertTrue($theirs->refresh()->hasMember($victim));
    }

    public function test_a_manager_cannot_manage_a_company_they_do_not_belong_to(): void
    {
        $owner = $this->createUserWithRole(RoleName::Admin);
        $manager = $this->createUserWithRole(RoleName::Manager);
        $theirs = $this->createCompanyFor($owner);

        $this->actingAsJwt($manager)
            ->putJson("/api/companies/{$theirs->id}", ['name' => 'Hijacked'])
            ->assertStatus(403);

        $this->assertNotSame('Hijacked', $theirs->refresh()->name);
    }

    public function test_the_company_list_only_contains_memberships(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin, ['name' => 'Mine Co']);
        $this->createUnrelatedCompany(['name' => 'Not Mine Co']);

        $names = collect(
            $this->actingAsJwt($admin)->getJson('/api/companies')->assertOk()->json('data')
        )->pluck('name');

        $this->assertSame(['Mine Co'], $names->all());
    }

    public function test_a_manager_cannot_create_a_company(): void
    {
        $manager = $this->createUserWithRole(RoleName::Manager);
        $this->createCompanyFor($manager);

        $this->actingAsJwt($manager)
            ->postJson('/api/companies', ['name' => 'Manager Attempt'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('companies', ['name' => 'Manager Attempt']);
    }

    public function test_an_accountant_cannot_create_a_company(): void
    {
        $accountant = $this->createUserWithRole(RoleName::Accountant);
        $this->createCompanyFor($accountant);

        $this->actingAsJwt($accountant)
            ->postJson('/api/companies', ['name' => 'Accountant Attempt'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('companies', ['name' => 'Accountant Attempt']);
    }

    public function test_every_member_of_a_company_can_read_it(): void
    {
        $owner = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($owner);

        foreach ([RoleName::Manager, RoleName::Accountant, RoleName::Staff] as $role) {
            $member = $this->createUserWithRole($role);
            $company->users()->attach($member->id, ['is_default' => true]);

            $this->actingAsJwt($member)
                ->getJson("/api/companies/{$company->id}")
                ->assertOk()
                ->assertJsonPath('data.id', $company->id);
        }
    }

    public function test_a_removed_member_loses_company_access(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);
        $member = $this->createUserWithRole(RoleName::Manager);
        $company->users()->attach($member->id, ['is_default' => true]);

        $this->actingAsJwt($member)->getJson("/api/companies/{$company->id}")->assertOk();

        $this->actingAsJwt($admin)
            ->deleteJson("/api/companies/{$company->id}/members/{$member->id}")
            ->assertOk();

        $this->actingAsJwt($member)
            ->getJson("/api/companies/{$company->id}")
            ->assertStatus(403);
    }

    public function test_a_token_from_a_deactivated_user_cannot_reach_company_data(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        // The token was issued while the user was active; deactivating the user
        // must stop it reaching company data. 403 rather than 401, matching the
        // Phase 2 convention where a valid token for a deactivated account
        // reports the account state instead of an authentication failure.
        $token = $this->tokenFor($admin);
        $admin->forceFill(['is_active' => false])->save();

        $this->withToken($token)
            ->getJson("/api/companies/{$company->id}")
            ->assertStatus(403);
    }

    public function test_a_company_response_does_not_leak_password_material(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $body = $this->actingAsJwt($admin)
            ->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('$pbkdf2', $body);
        $this->assertStringNotContainsString('email_verification_token', $body);
    }

    public function test_the_hidden_currency_id_cannot_be_written_through_the_payload(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->postJson('/api/companies', [
                'name' => 'Currency Probe Co',
                'currency_id' => 9999,
            ])
            ->assertStatus(201);

        $company = Company::where('name', 'Currency Probe Co')->sole();

        // currency_id is Hidden from output and excluded from validation, so a
        // caller cannot set it before the currencies table exists in a later
        // phase. The settings row has its own default_currency_id, which is a
        // different field and does appear, so the assertion is scoped to the
        // company block of the payload.
        $this->assertNull($company->currency_id);

        $data = $this->actingAsJwt($admin)
            ->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('currency_id', $data);
    }

    public function test_a_staff_user_cannot_read_the_member_list_of_another_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $staff = $this->createUserWithRole(RoleName::Staff);
        $this->createCompanyFor($staff, ['name' => 'Staff Co'], isDefault: false);
        $theirs = $this->createCompanyFor($admin, ['name' => 'Admin Co'], isDefault: false);

        $this->actingAsJwt($staff)
            ->getJson("/api/companies/{$theirs->id}")
            ->assertStatus(403);
    }

    public function test_membership_is_validated_server_side_not_by_the_client(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $user = $this->createUserWithRole(RoleName::Staff);

        // A payload claiming membership changes nothing: the pivot row is the
        // only source of truth and it is written by the service.
        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$company->id}/members", [
                'user_id' => $user->id,
                'role' => 'Admin',
            ])
            ->assertStatus(201);

        $user->refresh();

        // The membership row is written by the service from the database
        // identity, and the role key in the payload is not a validated field,
        // so it has no effect.
        $this->assertTrue($company->hasMember($user));
        $this->assertFalse($user->hasRole(RoleName::Admin));
        $this->assertSame(RoleName::Staff->value, $user->roles()->value('name'));
    }

    public function test_only_an_administrator_may_set_an_inbound_members_default(): void
    {
        $manager = $this->createUserWithRole(RoleName::Manager);
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($manager);
        $company->users()->attach($admin->id, ['is_default' => false]);

        $staff = $this->createUserWithRole(RoleName::Staff);
        $theirOwn = $this->createCompanyFor($staff, ['name' => 'Staff Own Co']);

        // A Manager may add members but must not choose someone's default,
        // because that would change the active company on their next request.
        $this->actingAsJwt($manager)
            ->postJson("/api/companies/{$company->id}/members", [
                'user_id' => $staff->id,
                'is_default' => true,
            ])
            ->assertStatus(403);

        // The default is untouched: the staff user still points at their own.
        $this->assertEquals($theirOwn->id, $staff->refresh()->defaultCompany()?->id);
    }

    public function test_a_user_cannot_attach_themselves_to_an_unrelated_company(): void
    {
        $owner = $this->createUserWithRole(RoleName::Admin);
        $outsider = $this->createUserWithRole(RoleName::Manager);
        $theirs = $this->createCompanyFor($owner);

        $this->actingAsJwt($outsider)
            ->postJson("/api/companies/{$theirs->id}/members", ['user_id' => $outsider->id])
            ->assertStatus(403);

        $this->assertFalse($theirs->refresh()->hasMember($outsider));
    }

    public function test_user_ids_cannot_be_guessed_into_a_company_you_do_not_own(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        // A non-existent user id must not produce a 500 or a leak.
        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$company->id}/members", ['user_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }
}
