import { ApiError } from "@/lib/api/errors";
import { apiFetch } from "@/lib/api/client";
import type { QueryParams } from "@/lib/api/types";
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

/** The token payload returned by `/auth/password` (no user object). */
export type AuthTokenResult = {
  token: string;
  token_type: string;
  expires_in: number;
};

type LaravelRequestOptions = {
  token?: string;
  companyId?: number | null;
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  query?: QueryParams;
  signal?: AbortSignal;
};

function laravelFetch<T>(
  path: string,
  { token, companyId, method = "GET", body, query, signal }: LaravelRequestOptions = {},
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
    query,
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

/**
 * `POST /api/auth/forgot-password` — request a reset link.
 *
 * The backend returns the same message whether or not the address exists, so
 * the caller must never try to distinguish the two.
 */
export function forgotPasswordRequest(email: string): Promise<void> {
  return laravelFetch<void>("/auth/forgot-password", {
    method: "POST",
    body: { email },
  });
}

/**
 * `GET /api/auth/reset-password/{token}?email=` — check a reset token.
 *
 * Returns the masked `email` the token belongs to when valid, and throws a
 * (400) `ApiError` when the token is stale or the address is unknown — the two
 * are deliberately indistinguishable.
 */
export function validateResetTokenRequest(
  token: string,
  email: string,
  signal?: AbortSignal,
): Promise<{ email: string }> {
  return laravelFetch<{ email: string }>(
    `/auth/reset-password/${encodeURIComponent(token)}`,
    { query: { email }, signal },
  );
}

/** `POST /api/auth/reset-password` — consume a token and set a new password. */
export function resetPasswordRequest(payload: {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
}): Promise<void> {
  return laravelFetch<void>("/auth/reset-password", {
    method: "POST",
    body: payload,
  });
}

/**
 * `PUT /api/auth/password` — change the authenticated user's password.
 *
 * The backend blacklists the token used for this request and returns a
 * replacement, which the BFF must re-seal into the session cookie.
 */
export function changePasswordRequest(
  token: string,
  payload: {
    current_password: string;
    new_password: string;
    new_password_confirmation: string;
  },
): Promise<AuthTokenResult> {
  return laravelFetch<AuthTokenResult>("/auth/password", {
    token,
    method: "PUT",
    body: payload,
  });
}

/** `POST /api/auth/email/verification-notification` — resend a verify email. */
export function sendEmailVerificationRequest(token: string): Promise<void> {
  return laravelFetch<void>("/auth/email/verification-notification", {
    token,
    method: "POST",
  });
}
