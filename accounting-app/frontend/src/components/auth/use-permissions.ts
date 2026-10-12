"use client";

import { useMemo } from "react";

import {
  hasAllPermissions,
  hasAnyPermission,
  hasPermission,
} from "@/lib/permissions/permissions";

import { useAuth } from "./auth-provider";

/**
 * Permission checks backed by the current user's roles.
 *
 * Rendering aid only — the backend always re-checks. Results fail closed while
 * the session is loading, so gated UI stays hidden rather than flashing.
 */
export function usePermissions() {
  const { permissions } = useAuth();

  return useMemo(
    () => ({
      permissions,
      can: (name: string) => hasPermission(permissions, name),
      canAny: (names: readonly string[]) => hasAnyPermission(permissions, names),
      canAll: (names: readonly string[]) => hasAllPermissions(permissions, names),
    }),
    [permissions],
  );
}
