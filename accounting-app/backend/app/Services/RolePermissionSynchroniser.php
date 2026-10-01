<?php

namespace App\Services;

use App\Enums\RoleName;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates and synchronises the roles and permissions declared in
 * config/authorization.php.
 *
 * Idempotent: safe to re-run after the configuration changes. Never run
 * automatically as part of a request.
 */
class RolePermissionSynchroniser
{
    public function sync(): array
    {
        $guard = (string) config('authorization.guard', 'api');

        $created = [];

        DB::transaction(function () use ($guard, &$created) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            foreach (config('authorization.permissions', []) as $name) {
                $exists = Permission::query()
                    ->where('name', $name)
                    ->where('guard_name', $guard)
                    ->exists();

                if (! $exists) {
                    Permission::create(['name' => $name, 'guard_name' => $guard]);
                    $created[] = "permission:{$name}";
                }
            }

            $all = Permission::where('guard_name', $guard)->get();

            foreach (config('authorization.roles', []) as $roleName => $permissions) {
                $role = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', $guard)
                    ->first();

                $isNew = false;

                if (! $role) {
                    $role = Role::create(['name' => $roleName, 'guard_name' => $guard]);
                    $isNew = true;
                }

                $resolved = in_array('*', $permissions, true)
                    ? $all->pluck('name')->all()
                    : $permissions;

                $missing = array_diff($resolved, $role->permissions->pluck('name')->all());

                if ($isNew || $missing !== []) {
                    $role->syncPermissions($resolved);
                    $created[] = "role:{$roleName}";
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });

        return $created;
    }

    /**
     * Ensure the four baseline application roles exist.
     *
     * Used by the registration flow so a self-registering user can be granted
     * the Staff role even on a freshly migrated database where the developer
     * has not yet run `app:sync-roles`.
     */
    public function ensureBaselineRoles(): void
    {
        $guard = (string) config('authorization.guard', 'api');

        foreach (RoleName::values() as $roleName) {
            Role::findOrCreate($roleName, $guard);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
