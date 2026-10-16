"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";

import type { UserCreatePayload, UserListFilters, UserUpdatePayload } from "./types";
import { toUserQuery } from "./validators";

/**
 * User administration backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/users*`), which
 * attaches the server-held bearer token. The users family is globally
 * authenticated but not company-scoped, so no `X-Company-Id` is involved.
 *
 * `index` takes `per_page: 100` (the API's maximum): the response carries no
 * pagination metadata, so reading the largest page the API allows is the only
 * way to show as complete a roster as the endpoint can return.
 */

export function fetchUsers(
  filters: UserListFilters = {},
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet("users", { query: toUserQuery(filters), signal });
}

export function fetchUser(id: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`users/${id}`, { signal });
}

export function createUser(payload: UserCreatePayload): Promise<unknown> {
  return backendPost("users", payload);
}

export function updateUser(id: number, payload: UserUpdatePayload): Promise<unknown> {
  return backendPut(`users/${id}`, payload);
}

/**
 * Deactivate a user.
 *
 * The backend performs a soft state change (`is_active = false`), never a
 * destructive delete, so accounting records stay attributable.
 */
export function deactivateUser(id: number): Promise<unknown> {
  return backendDelete(`users/${id}`);
}
