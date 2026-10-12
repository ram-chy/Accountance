/**
 * Name of the BFF session cookie.
 *
 * Kept in its own module (with no `next/headers` import) so `proxy.ts` can read
 * the same name without pulling server-only APIs into the proxy bundle.
 */
export const SESSION_COOKIE_NAME =
  process.env.SESSION_COOKIE_NAME?.trim() || "accountance_session";
