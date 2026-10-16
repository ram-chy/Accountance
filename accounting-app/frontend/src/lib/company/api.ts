"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";

import type {
  CompanyCreatePayload,
  CompanyProfilePayload,
  CompanySettingsUpdatePayload,
} from "./types";

/**
 * Company backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token. Company settings live under the
 * company-scoped `company` family, so the proxy also injects the active
 * `X-Company-Id`; the profile and lifecycle endpoints live under the global
 * authenticated `companies` family and carry no company header.
 *
 * Responses are returned as `unknown` and narrowed by `validators.ts`.
 * `apiFetch` unwraps the `{ data }` envelope, so a single resource resolves to
 * its object.
 */

export function fetchCompanySettings(signal?: AbortSignal): Promise<unknown> {
  return backendGet("company/settings", { signal });
}

export function updateCompanySettings(
  payload: CompanySettingsUpdatePayload,
): Promise<unknown> {
  return backendPut("company/settings", payload);
}

export function fetchCompany(id: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`companies/${id}`, { signal });
}

export function updateCompany(
  id: number,
  payload: CompanyProfilePayload,
): Promise<unknown> {
  return backendPut(`companies/${id}`, payload);
}

export function createCompany(payload: CompanyCreatePayload): Promise<unknown> {
  return backendPost("companies", payload);
}

/**
 * Deactivate or reactivate a company.
 *
 * Deactivation is the reversible alternative to deletion (records will always
 * reference the row), and both acts require `companies.delete` — only an Admin
 * holds it. The endpoint echoes the updated `CompanyResource`.
 */
export function deactivateCompany(id: number): Promise<unknown> {
  return backendPost(`companies/${id}/deactivate`);
}

export function activateCompany(id: number): Promise<unknown> {
  return backendPost(`companies/${id}/activate`);
}

/**
 * Add a member to a company.
 *
 * The backend accepts `user_id` (verified in `ManageCompanyMemberRequest`).
 * There is no member-listing endpoint, so the UI picks a user from the global
 * users list and the backend enforces membership.
 */
export function addCompanyMember(companyId: number, userId: number): Promise<unknown> {
  return backendPost(`companies/${companyId}/members`, { user_id: userId });
}

export function removeCompanyMember(
  companyId: number,
  userId: number,
): Promise<unknown> {
  return backendDelete(`companies/${companyId}/members/${userId}`);
}
