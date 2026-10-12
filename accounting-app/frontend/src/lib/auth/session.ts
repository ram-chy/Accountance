import { cookies } from "next/headers";

import { SESSION_COOKIE_NAME } from "./session-cookie";
import {
  decryptSessionPayload,
  encryptSessionPayload,
  type SessionPayload,
} from "./session-crypto";

/**
 * Server-side session cookie access (Next.js `cookies()`).
 *
 * This module is imported only by server code — route handlers and server
 * components — and is never bundled for the browser. The cookie it manages is
 * `HttpOnly`, so client JavaScript can neither read the JWT nor forge a
 * session.
 */

export { SESSION_COOKIE_NAME };

type CookieOptions = {
  httpOnly: boolean;
  secure: boolean;
  sameSite: "lax";
  path: string;
  maxAge?: number;
};

/**
 * Cookie attributes.
 *
 * `SameSite=Lax` blocks the cookie from riding cross-site POSTs (the primary
 * CSRF vector); `Secure` is forced on in production and never weakened for
 * local development. The mutating BFF routes additionally require a same-origin
 * request.
 */
export function sessionCookieOptions(maxAgeSeconds?: number): CookieOptions {
  return {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    ...(maxAgeSeconds !== undefined ? { maxAge: maxAgeSeconds } : {}),
  };
}

/** The decrypted, unexpired session, or null. */
export async function getSession(): Promise<SessionPayload | null> {
  const store = await cookies();

  try {
    const payload = await decryptSessionPayload(
      store.get(SESSION_COOKIE_NAME)?.value,
    );

    if (!payload || payload.expiresAt <= Date.now()) {
      return null;
    }

    return payload;
  } catch {
    // Reading a session must never 500 (e.g. a missing secret in a misconfigured
    // environment): degrade to "signed out" and let the app redirect. Mutating
    // writes still surface configuration errors loudly.
    return null;
  }
}

/** Seal a session payload into the cookie. */
export async function writeSession(payload: SessionPayload): Promise<void> {
  const store = await cookies();
  const value = await encryptSessionPayload(payload);
  const secondsRemaining = Math.floor((payload.expiresAt - Date.now()) / 1000);

  store.set(
    SESSION_COOKIE_NAME,
    value,
    sessionCookieOptions(secondsRemaining > 0 ? secondsRemaining : undefined),
  );
}

/** Remove the session cookie. */
export async function clearSession(): Promise<void> {
  const store = await cookies();
  store.set(SESSION_COOKIE_NAME, "", sessionCookieOptions(0));
}
