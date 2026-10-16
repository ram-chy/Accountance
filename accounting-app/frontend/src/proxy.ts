import { NextResponse, type NextRequest } from "next/server";

import { sanitizeNextPath } from "@/lib/auth/paths";
import { SESSION_COOKIE_NAME } from "@/lib/auth/session-cookie";
import { decryptSessionPayload } from "@/lib/auth/session-crypto";

/**
 * Auth gate (Next 16 proxy — the successor to middleware).
 *
 * Runs in the Node.js runtime and only checks that the encrypted cookie exists
 * and is unexpired: it never calls Laravel and never trusts the cookie's
 * contents beyond that. Full validation happens in the route handlers and server
 * components. Unauthenticated requests are bounced to `/login` with a safe
 * `next` back to the requested page.
 */
export async function proxy(request: NextRequest): Promise<NextResponse> {
  const { pathname, search } = request.nextUrl;
  const raw = request.cookies.get(SESSION_COOKIE_NAME)?.value;

  let authenticated = false;

  try {
    const payload = await decryptSessionPayload(raw);
    authenticated = payload !== null && payload.expiresAt > Date.now();
  } catch {
    // Unresolvable session secret (misconfiguration) fails closed.
    authenticated = false;
  }

  if (authenticated) {
    return NextResponse.next();
  }

  const loginUrl = new URL("/login", request.url);

  if (pathname !== "/") {
    const safe = sanitizeNextPath(`${pathname}${search}`);

    if (safe !== "/") {
      loginUrl.searchParams.set("next", safe);
    }
  }

  return NextResponse.redirect(loginUrl);
}

export const config = {
  // Protect everything except the BFF itself, the public authentication routes
  // (the password-recovery screens must be reachable by an unauthenticated
  // visitor — including from the emailed reset link, which is a full page
  // load), and static assets (any path containing a dot, e.g. `/favicon.ico`).
  matcher: [
    "/((?!api|login|forgot-password|reset-password|_next/static|_next/image|favicon.ico|.*\\..*).*)",
  ],
};
