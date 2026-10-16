<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * First-company onboarding.
 *
 * Public registration always grants the Staff role, and Staff holds only
 * `companies.view` - it cannot create a company. That is deliberate: the
 * product's provisioning model is that an administrator (created with
 * `app:create-admin`) creates the company and adds the registered user as a
 * member, which the membership endpoint already supports. These tests pin that
 * model down end to end and prove that a user with no company is refused
 * cleanly rather than crashing.
 */
class FirstCompanyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function registerStaff(string $email = 'sam.staff@example.com'): User
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'first_name' => 'Sam',
            'last_name' => 'Staff',
            'email' => $email,
            'mobile_no' => '+15551230000',
            'password' => 'Str0ng@Pass1',
            'password_confirmation' => 'Str0ng@Pass1',
        ])->assertCreated();

        return User::query()->where('email', $email)->firstOrFail();
    }

    public function test_public_registration_grants_only_the_staff_role(): void
    {
        $user = $this->registerStaff();

        $this->assertTrue($user->hasRole(RoleName::Staff->value));
        $this->assertSame(1, $user->getRoleNames()->count());
        $this->assertFalse($user->hasAnyRole([RoleName::Admin->value, RoleName::Manager->value]));
    }

    public function test_a_self_registered_staff_user_cannot_create_a_company(): void
    {
        $user = $this->registerStaff();

        $this->actingAsJwt($user)
            ->postJson('/api/companies', ['name' => 'Solo Co'])
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('companies', ['name' => 'Solo Co']);
    }

    public function test_a_user_with_no_company_gets_a_clear_predicable_refusal(): void
    {
        $user = $this->registerStaff();

        $this->actingAsJwt($user)
            ->getJson('/api/company')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'No company is associated with your account, or the selected company is inactive.',
            );
    }

    public function test_an_admin_provisions_a_company_and_adds_the_registered_user(): void
    {
        Notification::fake();

        $staff = $this->registerStaff();
        $admin = $this->createUserWithRole(RoleName::Admin);

        $companyId = $this->actingAsJwt($admin)
            ->postJson('/api/companies', ['name' => 'Provisioned Co'])
            ->assertCreated()
            ->json('data.id');

        // The administrative step: the already-registered user is added to the
        // new company by id and it is made their default, so a later request
        // with no company header has an unambiguous context.
        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$companyId}/members", [
                'user_id' => $staff->id,
                'is_default' => true,
            ])
            ->assertCreated();

        // The provisioned user can now resolve a company context.
        $this->actingAsJwt($staff)
            ->getJson('/api/company')
            ->assertOk()
            ->assertJsonPath('data.id', $companyId);
    }

    public function test_a_provisioned_user_can_switch_their_default_company(): void
    {
        Notification::fake();

        $staff = $this->registerStaff();
        $admin = $this->createUserWithRole(RoleName::Admin);

        $companyId = $this->actingAsJwt($admin)
            ->postJson('/api/companies', ['name' => 'Switch Co'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$companyId}/members", ['user_id' => $staff->id])
            ->assertCreated();

        $this->actingAsJwt($staff)
            ->postJson("/api/companies/{$companyId}/switch")
            ->assertOk();

        // The default is persisted: a later request with no header resolves the
        // same company, unambiguously.
        $this->actingAsJwt($staff)
            ->getJson('/api/company')
            ->assertOk()
            ->assertJsonPath('data.id', $companyId);
    }

    public function test_a_non_member_cannot_reach_a_company_by_header_or_switch(): void
    {
        Notification::fake();

        $staff = $this->registerStaff();
        $admin = $this->createUserWithRole(RoleName::Admin);

        $company = Company::query()
            ->findOrFail(
                $this->actingAsJwt($admin)
                    ->postJson('/api/companies', ['name' => 'Foreign Co'])
                    ->assertCreated()
                    ->json('data.id'),
            );

        // The id exists but the caller is not a member: 403, not a silent
        // fallback to the caller's own (absent) context.
        $this->actingAsJwt($staff)
            ->withCompanyContext($company)
            ->getJson('/api/company')
            ->assertStatus(403);

        $this->actingAsJwt($staff)
            ->postJson("/api/companies/{$company->getKey()}/switch")
            ->assertStatus(403);
    }

    public function test_an_unknown_company_id_is_not_found(): void
    {
        $staff = $this->registerStaff();

        $this->actingAsJwt($staff)
            ->withHeader(CompanyContext::HEADER, '999999999')
            ->getJson('/api/company')
            ->assertStatus(404);
    }
}
