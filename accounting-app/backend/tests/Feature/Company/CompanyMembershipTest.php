<?php

namespace Tests\Feature\Company;

use App\Enums\RoleName;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompanyMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_belong_to_multiple_companies(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $first = $this->createCompanyFor($admin, ['name' => 'First Co']);
        $second = $this->createCompanyFor($admin, ['name' => 'Second Co'], isDefault: false);

        $admin->refresh();

        $this->assertCount(2, $admin->companies);
        $this->assertTrue($admin->companies->contains($first));
        $this->assertTrue($admin->companies->contains($second));
    }

    public function test_only_one_company_is_the_default(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($admin, ['name' => 'First Co']);
        $second = $this->createCompanyFor($admin, ['name' => 'Second Co']);

        $secondPivot = DB::table('company_user')
            ->where('user_id', $admin->id)
            ->where('company_id', $second->id)
            ->value('is_default');

        $this->assertTrue((bool) $secondPivot);
        $this->assertEquals($second->id, $admin->refresh()->defaultCompany()->id);
    }

    public function test_the_database_rejects_a_second_default_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $first = $this->createCompanyFor($admin);
        $other = $this->createCompanyFor($admin, isDefault: false);

        $this->expectException(QueryException::class);

        // Bypasses the service entirely to prove the constraint itself holds.
        DB::table('company_user')->where([
            'user_id' => $admin->id,
            'company_id' => $other->id,
        ])->update(['is_default' => true]);
    }

    public function test_the_database_rejects_a_duplicate_membership(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->expectException(QueryException::class);

        DB::table('company_user')->insert([
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_adding_an_existing_member_is_rejected(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$company->id}/members", ['user_id' => $admin->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    public function test_an_admin_can_add_a_user_to_a_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $newcomer = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$company->id}/members", ['user_id' => $newcomer->id])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $newcomer->id);

        $this->assertTrue($company->refresh()->hasMember($newcomer));
    }

    public function test_a_new_member_can_be_made_default(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $newcomer = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$company->id}/members", [
                'user_id' => $newcomer->id,
                'is_default' => true,
            ])
            ->assertCreated();

        $this->assertEquals(
            $company->id,
            $newcomer->refresh()->defaultCompany()?->id,
        );
    }

    public function test_an_inactive_company_cannot_receive_a_default_member(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $newcomer = $this->createUserWithRole(RoleName::Staff);
        $inactive = $this->createCompanyFor($admin, ['is_active' => false], isDefault: false);

        $this->actingAsJwt($admin)
            ->postJson("/api/companies/{$inactive->id}/members", [
                'user_id' => $newcomer->id,
                'is_default' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_default');
    }

    public function test_a_non_member_cannot_be_added_by_a_manager_of_another_company(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $manager = $this->createUserWithRole(RoleName::Manager);
        $target = $this->createUserWithRole(RoleName::Staff);

        $managerCompany = $this->createCompanyFor($manager);
        $adminCompany = $this->createCompanyFor($admin);

        $this->actingAsJwt($manager)
            ->postJson("/api/companies/{$adminCompany->id}/members", ['user_id' => $target->id])
            ->assertStatus(403);

        $this->assertFalse($adminCompany->hasMember($target));
    }

    public function test_a_member_can_be_removed(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $member = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($admin);
        $company->users()->attach($member->id, ['is_default' => false]);

        $this->actingAsJwt($admin)
            ->deleteJson("/api/companies/{$company->id}/members/{$member->id}")
            ->assertOk();

        $this->assertFalse($company->refresh()->hasMember($member));
    }

    public function test_removing_a_non_member_returns_404(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $stranger = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($admin);

        $this->actingAsJwt($admin)
            ->deleteJson("/api/companies/{$company->id}/members/{$stranger->id}")
            ->assertStatus(404);
    }

    public function test_membership_records_are_removed_when_a_company_goes(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->id,
            'user_id' => $admin->id,
        ]);

        // Exercises the foreign key's ON DELETE CASCADE directly.
        Company::query()->whereKey($company->id)->delete();

        $this->assertDatabaseMissing('company_user', [
            'company_id' => $company->id,
        ]);
    }

    public function test_the_service_refuses_an_inactive_default(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $inactive = $this->createCompanyFor($admin, ['is_active' => false], isDefault: false);

        $this->expectException(ValidationException::class);

        app(CompanyService::class)->makeDefault($admin, $inactive);
    }

    public function test_the_service_refuses_a_non_member(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $stranger = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createUnrelatedCompany();

        $this->expectException(ValidationException::class);

        app(CompanyService::class)->assertMember($stranger, $company);
    }

    public function test_default_company_must_belong_to_the_user(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $stranger = $this->createUserWithRole(RoleName::Staff);
        $notMine = $this->createUnrelatedCompany();

        $this->actingAsJwt($stranger)
            ->postJson("/api/companies/{$notMine->id}/switch")
            ->assertStatus(403);

        $this->assertNull($stranger->refresh()->defaultCompany());
    }

    public function test_user_companies_relation_uses_pivot_defaults(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $pivot = $admin->companies()->firstWhere('companies.id', $company->id)->pivot;

        $this->assertTrue((bool) $pivot->is_default);
    }

    public function test_company_users_relation_is_inverse(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);

        $this->assertTrue($company->users->contains($admin));
        $this->assertInstanceOf(User::class, $company->users->first());
    }
}
