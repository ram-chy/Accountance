import { ApiError } from "@/lib/api/errors";
import { apiFetch } from "@/lib/api/client";
import {
  SERVER_API_BASE_URL,
  isServerApiConfigured,
} from "@/lib/api/server-config";

import type { SessionCompany, SessionUser } from "./types";

/**
 * Server-to-server Laravel client for the BFF.
 *
 * The bearer token is attached here and never leaves the server. Response
 * unwrapping, `ApiError`, request-id capture and validation-error parsing all
 * come from the existing Phase 1 client, so the BFF and the (future) browser
 * client behave identically.
 */

export type LoginResult = {
  token: string;
  token_type: string;
  expires_in: number;
  user: SessionUser;
};

type LaravelRequestOptions = {
  token?: string;
  companyId?: number | null;
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  signal?: AbortSignal;
};

function laravelFetch<T>(
  path: string,
  { token, companyId, method = "GET", body, signal }: LaravelRequestOptions = {},
): Promise<T> {
  // Without a server-only Laravel origin, every upstream call would silently
  // hit the Next app itself (or a relative URL). Fail fast with a clear error
  // so the misconfiguration surfaces immediately.
  if (!isServerApiConfigured) {
    return Promise.reject(
      new ApiError({
        status: 0,
        isNetworkError: true,
        message: "The server API base URL is not configured. Set API_BASE_URL.",
      }),
    );
  }

  const headers: Record<string, string> = {};

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  if (companyId) {
    headers["X-Company-Id"] = String(companyId);
  }

  return apiFetch<T>(path, {
    baseUrl: SERVER_API_BASE_URL,
    method,
    body,
    headers,
    signal,
  });
}

/** `POST /api/auth/login` — returns a JWT and the (roles-only) user. */
export function loginRequest(
  email: string,
  password: string,
): Promise<LoginResult> {
  return laravelFetch<LoginResult>("/auth/login", {
    method: "POST",
    body: { email, password },
  });
}

/**
 * `POST /api/auth/logout` — revokes the token used for this request.
 *
 * Called best-effort on sign-out: a backend hiccup must not stop the browser
 * from clearing its own session, so callers ignore failures.
 */
export function logoutRequest(token: string): Promise<void> {
  return laravelFetch<void>("/auth/logout", { token, method: "POST" });
}

/** `GET /api/auth/me` — the user with `roles[].permissions[]` loaded. */
export function currentUserRequest(token: string): Promise<SessionUser> {
  return laravelFetch<SessionUser>("/auth/me", { token });
}

/** `GET /api/companies` — every company the caller is a member of. */
export function companiesRequest(token: string): Promise<SessionCompany[]> {
  return laravelFetch<SessionCompany[]>("/companies", { token });
}

/**
 * `POST /api/companies/{id}/switch` — validates membership and records the
 * company as the caller's default. The backend echo of `X-Company-Id` is not
 * needed because the BFF stores the id in its own session cookie.
 */
export function switchCompanyRequest(
  token: string,
  companyId: number,
): Promise<SessionCompany> {
  return laravelFetch<SessionCompany>(`/companies/${companyId}/switch`, {
    token,
    method: "POST",
  });
}
