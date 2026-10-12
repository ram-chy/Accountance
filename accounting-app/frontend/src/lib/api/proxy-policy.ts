/**
 * Proxy policy for the authenticated backend proxy (`/api/backend/*`).
 *
 * The proxy is deliberately NOT a transparent pipe to Laravel. It is an
 * allow-list over the route families the frontend is expected to call, so a
 * bug or a malicious page can only ever reach the endpoints enumerated here.
 * Everything in this module is pure and framework-free so the policy can be
 * unit-tested without a Next.js runtime.
 *
 * The families below are copied from the verified `routes/api.php` contract:
 * each entry is a first path segment. `COMPANY_SCOPED_FAMILIES` are the routes
 * guarded by Laravel's `company.context` middleware and therefore require the
 * active company to be injected server-side as `X-Company-Id`.
 */

export const PROXY_METHODS = [
  "GET",
  "POST",
  "PUT",
  "PATCH",
  "DELETE",
] as const;

export type ProxyMethod = (typeof PROXY_METHODS)[number];

const COMPANY_SCOPED_FAMILIES = new Set([
  "company",
  "accounts",
  "accounting",
  "journals",
  "customers",
  "suppliers",
  "sales-invoices",
  "purchase-bills",
  "credit-debit-notes",
  "customer-receipts",
  "supplier-payments",
  "cash-bank-accounts",
  "cash-bank-transactions",
  "bank-reconciliations",
]);

/** Authenticated but not company-scoped (membership, not `company.context`). */
const GLOBAL_AUTHENTICATED_FAMILIES = new Set(["users", "companies"]);

const ALLOWED_FAMILIES = new Set([
  ...COMPANY_SCOPED_FAMILIES,
  ...GLOBAL_AUTHENTICATED_FAMILIES,
]);

/**
 * Conservative segment grammar: letters, digits, dot, underscore, tilde and
 * hyphen. This rejects percent-encoding (defeating double-encoded traversal),
 * backslashes, slashes, whitespace and control characters in one check. Dot is
 * allowed but `.` and `..` are rejected explicitly below.
 */
const SEGMENT_PATTERN = /^[A-Za-z0-9._~-]+$/;
const REQUEST_ID_PATTERN = /^[A-Za-z0-9-]{1,64}$/;

const MAX_SEGMENTS = 12;
const MAX_SEGMENT_LENGTH = 128;

/** Documented JSON request limit; large enough for full accounting documents. */
export const MAX_BODY_BYTES = 10 * 1024 * 1024;

export type PathValidation =
  | {
      ok: true;
      segments: string[];
      family: string;
      requiresCompany: boolean;
    }
  | { ok: false; status: number; message: string };

function reject(): PathValidation {
  // Deliberately indistinguishable from a genuine 404 so probing the proxy
  // reveals nothing about which backend routes exist.
  return { ok: false, status: 404, message: "The requested endpoint was not found." };
}

/**
 * Validate the catch-all path before it is used to build an upstream URL.
 *
 * Returns the normalized segments on success. Any path that could be used for
 * traversal, that is not in the allow-list, or that names a session-management
 * endpoint is rejected with a 404.
 */
export function validateProxyPath(raw: string[] | undefined): PathValidation {
  if (!raw || raw.length === 0 || raw.length > MAX_SEGMENTS) {
    return reject();
  }

  const segments: string[] = [];

  for (const segment of raw) {
    if (
      typeof segment !== "string" ||
      segment.length === 0 ||
      segment.length > MAX_SEGMENT_LENGTH ||
      segment === "." ||
      segment === ".." ||
      !SEGMENT_PATTERN.test(segment)
    ) {
      return reject();
    }

    segments.push(segment);
  }

  const family = segments[0]?.toLowerCase() ?? "";

  if (!ALLOWED_FAMILIES.has(family)) {
    return reject();
  }

  // `POST /companies/{id}/switch` mutates the caller's default company. The
  // same effect is exposed safely through the BFF's own `/api/session/company`
  // route, which validates membership, so the raw endpoint stays blocked.
  if (family === "companies" && segments[2] === "switch") {
    return reject();
  }

  return {
    ok: true,
    segments,
    family,
    requiresCompany: COMPANY_SCOPED_FAMILIES.has(family),
  };
}

/** Build the absolute upstream URL from validated segments and the query. */
export function buildUpstreamUrl(
  baseUrl: string,
  segments: string[],
  search: string,
): string {
  const path = segments.map((segment) => encodeURIComponent(segment)).join("/");
  const query = search && !search.startsWith("?") ? `?${search}` : search;
  return `${baseUrl}/${path}${query ?? ""}`;
}

/**
 * Pick the only client headers the proxy will forward upstream.
 *
 * `Authorization`, `Cookie`, `X-Company-Id`, `Host` and everything else are
 * dropped: they are either rebuilt server-side or must never cross the boundary.
 */
export function collectClientHeaders(
  headers: Headers,
): Record<string, string> {
  const forwarded: Record<string, string> = {};

  const accept = headers.get("accept");
  if (accept) {
    forwarded.Accept = accept;
  }

  const contentType = headers.get("content-type");
  if (contentType) {
    forwarded["Content-Type"] = contentType;
  }

  const requestId = headers.get("x-request-id");
  if (requestId && REQUEST_ID_PATTERN.test(requestId)) {
    forwarded["X-Request-Id"] = requestId;
  }

  return forwarded;
}

/**
 * Assemble the upstream headers. `Authorization` and `X-Company-Id` are written
 * last so a forwarded client header can never override the server-derived
 * values, even if `collectClientHeaders` is changed later.
 */
export function buildUpstreamHeaders(options: {
  token: string;
  companyId?: number;
  clientHeaders: Record<string, string>;
}): Record<string, string> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    ...options.clientHeaders,
    Authorization: `Bearer ${options.token}`,
  };

  if (options.companyId !== undefined) {
    headers["X-Company-Id"] = String(options.companyId);
  }

  return headers;
}

/**
 * Whitelist the upstream response headers returned to the browser.
 *
 * Only the content type and the correlation id are propagated; `Set-Cookie`,
 * `Location` and any other backend header stay server-side. Responses are
 * marked `no-store` because they are user- and company-specific.
 */
export function filterResponseHeaders(upstream: Headers): Headers {
  const headers = new Headers();

  const contentType = upstream.get("content-type");
  if (contentType) {
    headers.set("Content-Type", contentType);
  }

  const requestId = upstream.get("x-request-id");
  if (requestId) {
    headers.set("X-Request-Id", requestId);
  }

  headers.set("Cache-Control", "no-store");

  return headers;
}
