<?php

namespace Tests\Feature\Accounting;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Who may do what with cash and bank.
 *
 * Five permissions, and the point of testing them separately is that they are
 * genuinely separable: view, create, update, post and delete. A module that only
 * checked "can the user touch cash and bank" would give every role the whole
 * surface, and the interesting question - whether a Manager can approve a
 * posting - would never be asked.
 *
 * The role table below is a deliberate reconciliation of two sources. The
 * existing roles in this project give Manager read-only access to everything and
 * Staff nothing; the Phase 7 brief's own table contradicts itself on Manager
 * (it lists accounting.cash_bank.approve in one place and states read-only
 * elsewhere). This implementation follows the project's read-only Manager, and
 * therefore has no separate "approve" permission at all - posting is a mutation,
 * and inventing a permission the project has no role for would add a thing
 * nothing could ever be granted.
 */
class CashBankAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Accounts built per company, memoised.
     *
     * Codes are unique per company, so calling this twice for the same company
     * would collide on the unique index. Several tests need the chart in more
     * than one place - transferPayload() builds it, and the test body builds it
     * again - so it is cached rather than left to each test to thread around.
     *
     * @var array<int, array<string, Account>>
     */
    private array $charts = [];

    /**
     * @return array<string, Account>
     */
    private function chart(Company $company): array
    {
        return $this->charts[$company->getKey()] ??= [
            'cash' => Account::factory()->for($company)->cash()->create(['code' => '1010', 'name' => 'Cash']),
            'bank' => Account::factory()->for($company)->bank()->create(['code' => '1020', 'name' => 'Bank']),
            'plain' => Account::factory()->for($company)->asset()->create([
                'code' => '1100',
                'name' => 'Receivable',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transferPayload(Company $company): array
    {
        $chart = $this->chart($company);

        return [
            'transaction_date' => '2027-05-01',
            'amount' => '10.0000',
            'source_account_id' => $chart['bank']->getKey(),
            'destination_account_id' => $chart['cash']->getKey(),
        ];
    }

    /**
     * The five permissions this module defines, written out rather than derived
     * from the enum.
     *
     * The point of spelling them out is that this test is checking a *contract*
     * between three places that could each disagree: the enum cases, the entries
     * in config/authorization.php, and the policy methods that consult them. If
     * the expected set were computed from the enum, adding a sixth case would
     * silently widen the expectation and the test would keep passing while the
     * role table went unexamined.
     */
    private static function allCashBankPermissions(): array
    {
        return [
            PermissionName::CashBankView->value,
            PermissionName::CashBankCreate->value,
            PermissionName::CashBankUpdate->value,
            PermissionName::CashBankPost->value,
            PermissionName::CashBankDelete->value,
        ];
    }

    /**
     * @return array<string, array{0: RoleName, 1: array<int, string>}>
     */
    public static function rolePermissionProvider(): array
    {
        return [
            'admin has everything' => [RoleName::Admin, self::allCashBankPermissions()],
            'accountant has everything' => [RoleName::Accountant, self::allCashBankPermissions()],
            'manager is read-only' => [RoleName::Manager, [PermissionName::CashBankView->value]],
            'staff has nothing' => [RoleName::Staff, []],
        ];
    }

    #[Test]
    #[DataProvider('rolePermissionProvider')]
    public function each_role_holds_exactly_the_intended_cash_bank_permissions(
        RoleName $role,
        array $expected
    ): void {
        $user = $this->createUserWithRole($role);

        $granted = collect(self::allCashBankPermissions())
            ->filter(fn (string $permission) => $user->fresh()->can($permission))
            ->values()
            ->all();

        sort($granted);
        sort($expected);

        $this->assertSame($expected, $granted);
    }

    /**
     * The enum, the config and the database must all name the same five
     * permissions. A permission in the enum but absent from config would be a
     * string nothing can ever grant.
     */
    #[Test]
    public function every_cash_bank_permission_in_the_enum_is_registered_in_the_config(): void
    {
        $registered = config('authorization.permissions');

        foreach (self::allCashBankPermissions() as $permission) {
            $this->assertContains(
                $permission,
                $registered,
                "{$permission} is a PermissionName case but is not in config/authorization.php."
            );
        }

        $declared = collect(PermissionName::cases())
            ->map(fn (PermissionName $case) => $case->value)
            ->filter(fn (string $value) => str_starts_with($value, 'accounting.cash_bank.'));

        $this->assertCount(
            count(self::allCashBankPermissions()),
            $declared->all(),
            'A cash/bank permission was added to the enum but not listed in this test.'
        );
    }

    /**
     * A Manager can read the list but not create, edit, post or delete.
     *
     * Asserted endpoint by endpoint rather than through the permission strings
     * above, because a permission can be granted and still not be wired to the
     * route that was supposed to consult it. That mismatch is the failure this
     * test exists to catch.
     */
    #[Test]
    public function a_manager_can_read_but_not_change(): void
    {
        $manager = $this->createUserWithRole(RoleName::Manager);
        $company = $this->createCompanyFor($manager);
        $chart = $this->chart($company);

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-transactions')
            ->assertSuccessful();

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-accounts')
            ->assertSuccessful();

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->postJson('/api/cash-bank-transactions/transfers', $this->transferPayload($company))
            ->assertForbidden();

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$chart['plain']->getKey()}", [
                'cash_bank_kind' => 'CASH',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('cash_bank_transactions', 0);
        $this->assertNull($chart['plain']->refresh()->cash_bank_kind);
    }

    #[Test]
    public function staff_are_refused_everywhere(): void
    {
        $staff = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($staff);
        $chart = $this->chart($company);

        $this->actingAsJwt($staff)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-transactions')
            ->assertForbidden();

        $this->actingAsJwt($staff)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-accounts')
            ->assertForbidden();

        $this->actingAsJwt($staff)
            ->withCompanyContext($company)
            ->postJson('/api/cash-bank-transactions/deposits', [
                'transaction_date' => '2027-05-01',
                'amount' => '10.0000',
                'source_account_id' => $chart['plain']->getKey(),
                'destination_account_id' => $chart['bank']->getKey(),
            ])
            ->assertForbidden();

        $this->actingAsJwt($staff)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$chart['plain']->getKey()}", [
                'cash_bank_kind' => 'CASH',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('cash_bank_transactions', 0);
    }

    /**
     * Posting is a separate permission from creating. If it were not, being able
     * to record a draft would imply being able to move the money, which is the
     * whole distinction a review step exists to create.
     */
    #[Test]
    public function posting_is_gated_separately_from_creating(): void
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);
        $this->makePeriodFor($company, '2027-05-01');

        $this->assertTrue($user->fresh()->can(PermissionName::CashBankCreate->value));

        $created = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/cash-bank-transactions/transfers', $this->transferPayload($company))
            ->assertCreated();

        /*
         * Take the post permission away from this user's role, without touching
         * their other permissions and without deleting the permission itself.
         *
         * It has to be detached from the *role*: the permission reaches the user
         * through their role, so removing a direct grant would achieve nothing.
         * The cache has to be cleared explicitly too - Spatie memoises the
         * permission-to-role mapping in the cache store, so a direct database
         * write is invisible to the Gate until something forgets it.
         * RolePermissionSynchroniser does exactly this after every write it
         * makes, for the same reason.
         */
        $permission = Permission::query()
            ->where('name', PermissionName::CashBankPost->value)
            ->firstOrFail();

        $user->roles()->firstOrFail()->permissions()->detach($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(
            $user->fresh()->can(PermissionName::CashBankPost->value),
            'Precondition: the post permission has been revoked for this user.'
        );
        $this->assertTrue(
            $user->fresh()->can(PermissionName::CashBankCreate->value),
            'Precondition: the create permission is still held.'
        );

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$created->json('data.id')}/post")
            ->assertForbidden();

        $this->assertDatabaseMissing('journals', ['source_type' => 'cash_bank_transaction']);
    }

    /**
     * An unauthenticated caller gets nowhere at all.
     */
    #[Test]
    public function guest_access_is_refused(): void
    {
        $company = $this->createUnrelatedCompany();

        $this->getJson('/api/cash-bank-transactions')->assertUnauthorized();
        $this->getJson('/api/cash-bank-accounts')->assertUnauthorized();
        $this->postJson('/api/cash-bank-transactions/transfers', $this->transferPayload($company))
            ->assertUnauthorized();
    }

    /**
     * An Accountant, who holds every cash/bank permission, can do every action
     * the policy defines.
     *
     * The complementary check to `a_manager_can_read_but_not_change`. Together
     * they pin the policy down from both ends: that it refuses the role without a
     * permission, and that it does not accidentally refuse the role with one.
     */
    #[Test]
    public function an_accountant_passes_every_ability_the_policy_defines(): void
    {
        $accountant = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($accountant);
        $this->makePeriodFor($company, '2027-05-01');

        $created = $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->postJson('/api/cash-bank-transactions/transfers', $this->transferPayload($company))
            ->assertCreated();

        $id = $created->json('data.id');

        // viewAny and create, exercised above; now view, update, post, delete.
        $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->getJson("/api/cash-bank-transactions/{$id}")
            ->assertSuccessful();

        $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-transactions/{$id}", ['notes' => 'Reviewed'])
            ->assertSuccessful();

        $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$id}/post")
            ->assertSuccessful();

        // Delete is denied on a posted document by lifecycle, not by permission -
        // an Accountant with the delete permission still must not remove a posted
        // entry. Getting 422 rather than 403 proves the permission passed and the
        // lifecycle rule is what stopped it.
        $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->deleteJson("/api/cash-bank-transactions/{$id}")
            ->assertStatus(422);

        // And the same delete permission succeeds on a draft.
        $second = $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->postJson('/api/cash-bank-transactions/transfers', $this->transferPayload($company))
            ->assertCreated();

        $this->actingAsJwt($accountant)
            ->withCompanyContext($company)
            ->deleteJson("/api/cash-bank-transactions/{$second->json('data.id')}")
            ->assertSuccessful();
    }

    /**
     * An Accountant who is a member of the company can act on it; a user from
     * another company cannot, even holding identical permissions.
     */
    #[Test]
    public function permission_without_membership_grants_nothing(): void
    {
        $accountant = $this->createUserWithRole(RoleName::Accountant);
        $theirCompany = $this->createCompanyFor($accountant);

        $otherCompany = $this->createUnrelatedCompany();

        $this->assertTrue($accountant->fresh()->can(PermissionName::CashBankCreate->value));

        // The other company has no members at all, so there is nobody to switch to.
        $this->actingAsJwt($accountant)
            ->withCompanyContext($otherCompany)
            ->getJson('/api/cash-bank-transactions')
            ->assertForbidden();

        $this->actingAsJwt($accountant)
            ->withCompanyContext($theirCompany)
            ->getJson('/api/cash-bank-transactions')
            ->assertSuccessful();
    }
}
