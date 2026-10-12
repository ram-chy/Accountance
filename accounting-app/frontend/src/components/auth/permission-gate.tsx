"use client";

import type { ReactNode } from "react";

import { usePermissions } from "./use-permissions";

type PermissionGateProps = {
  /** Require a single permission. */
  permission?: string;
  /** Require at least one of these permissions. */
  anyOf?: readonly string[];
  /** Require all of these permissions. */
  allOf?: readonly string[];
  /** Rendered when the check fails. Defaults to nothing. */
  fallback?: ReactNode;
  children: ReactNode;
};

/**
 * Conditionally render UI based on the current user's permissions.
 *
 * Convenience wrapper over `usePermissions` for the common case. Not a security
 * control: never rely on it to protect data — the API enforces authorisation.
 */
export function PermissionGate({
  permission,
  anyOf,
  allOf,
  fallback = null,
  children,
}: PermissionGateProps) {
  const { can, canAny, canAll } = usePermissions();

  let allowed = true;

  if (permission) {
    allowed = allowed && can(permission);
  }
  if (anyOf) {
    allowed = allowed && canAny(anyOf);
  }
  if (allOf) {
    allowed = allowed && canAll(allOf);
  }

  return <>{allowed ? children : fallback}</>;
}
