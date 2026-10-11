/**
 * API configuration.
 *
 * The base URL is injected at build time from `NEXT_PUBLIC_API_BASE_URL` so the
 * browser client can reach the Laravel backend directly. It includes the
 * `/api` prefix (for example `http://localhost:8000/api`).
 *
 * NOTE (Phase 2): the token-storage decision from the Phase 0 blueprint was
 * left open. A direct browser client is used for the foundation and the client
 * exposes a request-header hook (see `client.ts`) so Phase 2 can attach auth and
 * company-context headers without rewriting call sites — or swap to a
 * same-origin BFF by changing this base URL.
 */

const rawBaseUrl = process.env.NEXT_PUBLIC_API_BASE_URL ?? "";

/** Normalised base URL without a trailing slash. Empty when not configured. */
export const API_BASE_URL = rawBaseUrl.replace(/\/+$/, "");

/** Whether a base URL has been provided for this build. */
export const isApiConfigured = API_BASE_URL.length > 0;

/** Default page size used by the pagination adapter when none is supplied. */
export const DEFAULT_PER_PAGE = 25;
