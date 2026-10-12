import { ApiError } from "@/lib/api/errors";

import { companiesRequest, currentUserRequest } from "./laravel";
import type { SessionCompany, SessionSnapshot } from "./types";

/**
 * Assemble the browser-facing session snapshot from verified Laravel reads.
 *
 * `/auth/me` is used (not the login response) because only it loads
 * `roles[].permissions[]`; the login payload loads roles without permissions.
 */

export type BuiltSnapshot = {
  snapshot: SessionSnapshot;
  /** The company id that should be persisted in the cookie, if any. */
  companyId: number | null;
};

/**
 * Choose the active company.
 *
 * Preference order: the previously selected id (if the user is still an active
 * member of it), then the backend default, then the first active membership.
 * Inactive companies are never selectable, mirroring `CompanyService`.
 */
export function pickActiveCompany(
  companies: SessionCompany[],
  preferredCompanyId?: number | null,
): SessionCompany | null {
  const active = companies.filter((company) => company.is_active);

  if (active.length === 0) {
    return null;
  }

  if (preferredCompanyId !== undefined && preferredCompanyId !== null) {
    const preferred = active.find((company) => company.id === preferredCompanyId);
    if (preferred) {
      return preferred;
    }
  }

  return active.find((company) => company.is_default) ?? active[0];
}

export async function buildSessionSnapshot(
  token: string,
  preferredCompanyId?: number | null,
): Promise<BuiltSnapshot> {
  const user = await currentUserRequest(token);

  let companies: SessionCompany[] = [];

  try {
    companies = await companiesRequest(token);
  } catch (error) {
    // A user without `companies.view` cannot list companies; that is not a
    // reason to fail the whole session. Any other error (including a 401) is
    // rethrown so the caller can react to an expired session.
    if (!(error instanceof ApiError) || error.status !== 403) {
      throw error;
    }
  }

  const active = pickActiveCompany(companies, preferredCompanyId);

  return {
    snapshot: { user, companies, activeCompanyId: active?.id ?? null },
    companyId: active?.id ?? null,
  };
}
