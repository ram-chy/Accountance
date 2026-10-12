/**
 * Server-only API configuration for the BFF.
 *
 * The browser never talks to Laravel directly: it calls the same-origin
 * `/api/session/*` routes, which read the encrypted httpOnly session cookie and
 * attach the bearer token server-to-server. This module therefore holds the
 * Laravel origin for the server runtime only.
 *
 * Only `process.env.API_BASE_URL` is intended for production. The
 * `NEXT_PUBLIC_API_BASE_URL` fallback lets an existing Phase 1 setup keep working
 * locally without adding a second variable; it is not a secret (the base URL is
 * not sensitive), so falling back to it leaks nothing. Secrets (the JWT, the
 * cookie encryption key) are never read here.
 */

const rawServerBaseUrl =
  process.env.API_BASE_URL ?? process.env.NEXT_PUBLIC_API_BASE_URL ?? "";

/** Laravel base URL including the `/api` prefix, without a trailing slash. */
export const SERVER_API_BASE_URL = rawServerBaseUrl.replace(/\/+$/, "");

/** Whether the server has a Laravel origin configured for this build/run. */
export const isServerApiConfigured = SERVER_API_BASE_URL.length > 0;
