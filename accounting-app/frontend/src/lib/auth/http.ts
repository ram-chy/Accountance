/**
 * Small request helpers shared by the BFF route handlers.
 */

/**
 * Verify a state-changing request originates from this application.
 *
 * `SameSite=Lax` already stops the session cookie from being sent on a
 * cross-site POST. This is defence in depth against login-CSRF (which does not
 * need an existing cookie): when the browser sends an `Origin` header it must
 * match the request host. Non-browser callers with no `Origin` are allowed
 * unless they explicitly declare a cross-site `Sec-Fetch-Site`.
 */
export function isSameOriginRequest(request: Request): boolean {
  const origin = request.headers.get("origin");

  if (origin) {
    const host = request.headers.get("host");
    if (!host) {
      return false;
    }

    try {
      return new URL(origin).host === host;
    } catch {
      return false;
    }
  }

  const site = request.headers.get("sec-fetch-site");
  return site === null || site !== "cross-site";
}

/** Parse a JSON body, returning null for an absent or malformed payload. */
export async function readJsonBody(
  request: Request,
): Promise<Record<string, unknown> | null> {
  try {
    const parsed: unknown = await request.json();
    return typeof parsed === "object" && parsed !== null
      ? (parsed as Record<string, unknown>)
      : null;
  } catch {
    return null;
  }
}
