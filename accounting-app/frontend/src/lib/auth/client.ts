"use client";

import { apiFetch, type ApiFetchOptions } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import {
  notifyUnauthorized,
  onUnauthorized,
} from "@/lib/api/unauthorized";

import type { SessionCompany, SessionSnapshot } from "./types";

/**
 * Browser-side client for the same-origin BFF.
 *
 * Passing `baseUrl: ""` targets the current origin, so requests hit
 * `/api/session/*` and carry the httpOnly cookie. The Laravel origin and the JWT
 * stay on the server; nothing here can reach the API directly.
 *
 * The 401 signal is shared with the business proxy client via
 * `lib/api/unauthorized`, so a session expiry is handled once for every
 * authenticated call.
 */

export { onUnauthorized };

type BffRequestOptions = Omit<ApiFetchOptions, "baseUrl"> & {
  /**
   * Whether a 401 should trigger the global session-expired handling. Disabled
   * for login (401 means bad credentials) and logout (already signing out).
   */
  handleUnauthorized?: boolean;
};

function bff<T>(path: string, options: BffRequestOptions = {}): Promise<T> {
  const { handleUnauthorized = true, ...rest } = options;

  return apiFetch<T>(path, { ...rest, baseUrl: "" }).catch((error: unknown) => {
    if (handleUnauthorized && error instanceof ApiError && error.status === 401) {
      notifyUnauthorized();
    }

    throw error;
  });
}

/** Load the current session snapshot; 401 means signed out. */
export function fetchSession(): Promise<SessionSnapshot> {
  return bff<SessionSnapshot>("/api/session/me");
}

/** Exchange credentials for a session. A 401 means invalid credentials. */
export function loginSession(
  email: string,
  password: string,
): Promise<SessionSnapshot> {
  return bff<SessionSnapshot>("/api/session/login", {
    method: "POST",
    body: { email, password },
    handleUnauthorized: false,
  });
}

/** Clear the session (best effort server-side, always client-side). */
export function logoutSession(): Promise<void> {
  return bff<void>("/api/session/logout", {
    method: "POST",
    handleUnauthorized: false,
  });
}

/** Switch the active company. */
export function switchCompany(companyId: number): Promise<SessionCompany> {
  return bff<SessionCompany>("/api/session/company", {
    method: "POST",
    body: { companyId },
  });
}

/**
 * Request a password reset link.
 *
 * Always resolves to the same generic outcome; the UI must not imply that the
 * address was found (the backend cannot confirm it without leaking existence).
 */
export function requestPasswordReset(email: string): Promise<void> {
  return bff<void>("/api/session/forgot-password", {
    method: "POST",
    body: { email },
    handleUnauthorized: false,
  });
}

/** Check whether a reset token is still usable, and for which address. */
export function validateResetToken(
  token: string,
  email: string,
  signal?: AbortSignal,
): Promise<{ email: string }> {
  return bff<{ email: string }>(
    `/api/session/reset-password/${encodeURIComponent(token)}`,
    { query: { email }, signal, handleUnauthorized: false },
  );
}

/** Consume a reset token and set a new password. */
export function resetPassword(payload: {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
}): Promise<void> {
  return bff<void>("/api/session/reset-password", {
    method: "POST",
    body: payload,
    handleUnauthorized: false,
  });
}

/**
 * Change the authenticated user's password.
 *
 * The BFF re-seals the replacement token into the httpOnly cookie, so the
 * session survives and the old token can no longer be replayed.
 */
export function changePassword(payload: {
  current_password: string;
  new_password: string;
  new_password_confirmation: string;
}): Promise<{ changed: boolean }> {
  return bff<{ changed: boolean }>("/api/session/password", {
    method: "PUT",
    body: payload,
  });
}

/** Resend the verification email for the signed-in user. */
export function resendVerificationEmail(): Promise<void> {
  return bff<void>("/api/session/verify-email", { method: "POST" });
}
