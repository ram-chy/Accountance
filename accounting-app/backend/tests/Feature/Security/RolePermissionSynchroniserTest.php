<?php

namespace Tests\Feature\Security;

use App\Enums\RoleName;
use App\Models\User;
use App\Services\RolePermissionSynchroniser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `app:sync-roles` is the deployment step that materialises the roles and
 * permissions declared in config/authorization.php. It is run as an operator
 * action, so its behaviour - especially what it will and will not overwrite -
 * has to be pinned down rather than inferred from its name.
 */
class RolePermissionSynchroniserTest extends TestCase
{
    use RefreshDatabase;

    private function sync(): RolePermissionSynchroniser
    {
        $synchroniser = app(RolePermissionSynchroniser::class);
        $synchroniser->sync();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $synchroniser;
    }

    public function test_sync_creates_every_declared_permission_and_role(): void
    {
        $this->sync();

        $this->assertSame(
            count(config('authorization.permissions')),
            Permission::where('guard_name', 'api')->count(),
        );

        $this->assertSame(
            count(config('authorization.roles')),
            Role::where('guard_name', 'api')->count(),
        );
    }

    public function test_a_second_sync_is_a_no_op(): void
    {
        $synchroniser = app(RolePermissionSynchroniser::class);

        $first = $synchroniser->sync();
        $second = $synchroniser->sync();

        $this->assertNotSame([], $first, 'the first sync should report work done');
        $this->assertSame([], $second, 'a repeat sync must change nothing');
    }

    public function test_the_command_reports_that_a_repeat_run_is_already_up_to_date(): void
    {
        $this->artisan('app:sync-roles')->assertExitCode(0);

        $this->artisan('app:sync-roles')
            ->expectsOutput('Roles and permissions are already up to date.')
            ->assertExitCode(0);
    }

    public function test_sync_preserves_user_role_assignments(): void
    {
        $user = $this->createUserWithRole(RoleName::Staff);

        app(RolePermissionSynchroniser::class)->sync();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($user->fresh()->hasRole(RoleName::Staff->value));
    }

    public function test_sync_preserves_direct_user_permissions(): void
    {
        $this->sync();

        $user = User::factory()->create();
        $user->givePermissionTo('users.view');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($user->fresh()->hasDirectPermission('users.view'));

        app(RolePermissionSynchroniser::class)->sync();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(
            $user->fresh()->hasDirectPermission('users.view'),
            'a sync must not disturb direct (non-role) grants',
        );
    }

    public function test_sync_never_deletes_permission_records(): void
    {
        $this->sync();

        // A permission that exists in the table but is not declared in config.
        Permission::create(['name' => 'zzz.legacy', 'guard_name' => 'api']);

        app(RolePermissionSynchroniser::class)->sync();

        $this->assertDatabaseHas('permissions', [
            'name' => 'zzz.legacy',
            'guard_name' => 'api',
        ]);
    }

    public function test_a_role_permission_set_is_replaced_by_the_config_when_a_sync_runs(): void
    {
        $this->sync();

        $role = Role::findByName(RoleName::Manager->value, 'api');

        $extra = Permission::create(['name' => 'zzz.extra', 'guard_name' => 'api']);
        $role->givePermissionTo($extra);

        // A config permission missing from the role is what makes the next sync
        // actually re-sync the role; without it an unchanged role is left alone.
        $role->revokePermissionTo('users.view');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(RolePermissionSynchroniser::class)->sync();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role->refresh();

        $this->assertTrue($role->hasPermissionTo('users.view'), 'declared permission restored');
        $this->assertFalse(
            $role->hasPermissionTo('zzz.extra'),
            'an undeclared role permission is dropped when the role is re-synced',
        );
    }

    public function test_the_admin_role_resolves_to_every_permission(): void
    {
        $this->sync();

        $admin = Role::findByName(RoleName::Admin->value, 'api');

        $this->assertSame(
            count(config('authorization.permissions')),
            $admin->permissions()->count(),
        );
    }

    public function test_ensure_baseline_roles_creates_roles_without_granting_permissions(): void
    {
        // Called by the registration flow so a fresh database can still assign
        // Staff, without running the full synchronisation.
        app(RolePermissionSynchroniser::class)->ensureBaselineRoles();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RoleName::values() as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role, 'guard_name' => 'api']);
        }

        $this->assertSame(0, Permission::count());
    }
}
