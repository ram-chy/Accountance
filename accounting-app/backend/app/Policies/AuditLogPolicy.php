<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization for audit logs. Read-only by design.
 */
class AuditLogPolicy
{
    use HandlesAuthorization;

    public function view(User $user, AuditLog $auditLog): bool
    {
        if (! $user->can(PermissionName::AuditView->value)) {
            return false;
        }

        return $auditLog->company_id === null
            ? false
            : $user->belongsToCompany((int) $auditLog->company_id);
    }

    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::AuditView->value);
    }

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }

    public function restore(): bool
    {
        return false;
    }

    public function forceDelete(): bool
    {
        return false;
    }
}
