import { describe, expect, it } from "vitest";

import { config } from "./proxy";

/**
 * Phase 21 regression: the password-recovery screens are public.
 *
 * A signed-out visitor following the emailed reset link (a full page load, not
 * a client-side navigation) must reach `/reset-password` and `/forgot-password`
 * without being bounced to `/login`; previously the matcher gated them and the
 * recovery flow was unreachable for exactly the users who need it.
 *
 * The matcher string is compiled by Next.js into a start-anchored RegExp
 * against the pathname (Next docs: matcher patterns "are anchored to the
 * start of the path"); testing it the same way mirrors that behaviour.
 */
const matcher = new RegExp(`^(?:${config.matcher[0] ?? ""})`);

/** Paths the proxy must NOT run on (public — no session required). */
const PUBLIC_PATHS = [
  "/login",
  "/forgot-password",
  "/reset-password",
  "/api/backend/journals",
  "/api/session/login",
  "/api/session/reset-password",
  "/_next/static/chunks/main.js",
  "/_next/image",
  "/favicon.ico",
  "/robots.txt",
];

/** Paths the proxy MUST run on (unauthenticated visitors bounce to /login). */
const PROTECTED_PATHS = [
  "/",
  "/journals",
  "/settings/users",
  "/reports/trial-balance",
  "/companies/new",
  "/fixed-assets",
  "/budgets/3",
];

describe("proxy matcher (auth gate routing)", () => {
  it.each(PUBLIC_PATHS)("does not gate the public path %s", (path) => {
    expect(matcher.test(path)).toBe(false);
  });

  it.each(PROTECTED_PATHS)("gates the protected path %s", (path) => {
    expect(matcher.test(path)).toBe(true);
  });

  it("gates look-alike paths that do not match a public prefix exactly", () => {
    // The exclusion must anchor on the first segment's prefix: an app path
    // that merely *contains* a public name stays protected.
    expect(matcher.test("/journals/forgot-password")).toBe(true);
    expect(matcher.test("/settings/reset-password")).toBe(true);
  });
});
