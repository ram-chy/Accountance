import type { SessionUser } from "@/lib/auth/types";

/**
 * Permission helpers.
 *
 * Permissions are carried on the user as `roles[].permissions[].name` (there is
 * no flat `permissions` field on `UserResource`). The backend authorises every
 * request regardless of what the UI shows, so these helpers only decide what to
 * render; they must never be treated as a security boundary.
 *
 * They also fail closed: an unknown user or missing role data yields no
 * permissions, so gated UI simply stays hidden until real data arrives.
 */

/** Reserved value the backend uses for "all permissions" (e.g. the Admin role). */
const WILDCARD = "*";

export function collectPermissions(
  user: SessionUser | null | undefined,
): ReadonlySet<string> {
  const names = new Set<string>();

  if (!user) {
    return names;
  }

  for (const role of user.roles ?? []) {
    for (const permission of role.permissions ?? []) {
      if (permission?.name) {
        names.add(permission.name);
      }
    }
  }

  return names;
}

export function hasPermission(
  permissions: ReadonlySet<string>,
  required: string,
): boolean {
  if (!required) {
    return false;
  }

  return permissions.has(WILDCARD) || permissions.has(required);
}

export function hasAnyPermission(
  permissions: ReadonlySet<string>,
  required: readonly string[],
): boolean {
  if (required.length === 0) {
    return true;
  }

  return required.some((name) => hasPermission(permissions, name));
}

export function hasAllPermissions(
  permissions: ReadonlySet<string>,
  required: readonly string[],
): boolean {
  return required.every((name) => hasPermission(permissions, name));
}
