/**
 * Safe post-login redirect handling.
 *
 * `next` arrives from a query string, so it is attacker-controlled. Only
 * same-origin absolute paths are accepted; anything else (absolute URLs,
 * protocol-relative `//evil`, backslash tricks, or the login page itself) is
 * collapsed to the app root. This prevents open-redirect abuse.
 */

export function sanitizeNextPath(
  value: string | string[] | undefined | null,
): string {
  const raw = Array.isArray(value) ? value[0] : value;

  if (!raw || !raw.startsWith("/") || raw.startsWith("//")) {
    return "/";
  }

  if (raw.includes("\\")) {
    return "/";
  }

  if (raw === "/login" || raw.startsWith("/login?") || raw.startsWith("/login/")) {
    return "/";
  }

  return raw;
}
