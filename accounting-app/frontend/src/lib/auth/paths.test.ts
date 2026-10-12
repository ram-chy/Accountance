import { describe, expect, it } from "vitest";

import { sanitizeNextPath } from "./paths";

describe("sanitizeNextPath", () => {
  it("returns the app root for missing values", () => {
    expect(sanitizeNextPath(undefined)).toBe("/");
    expect(sanitizeNextPath(null)).toBe("/");
    expect(sanitizeNextPath("")).toBe("/");
  });

  it("keeps same-origin absolute paths", () => {
    expect(sanitizeNextPath("/")).toBe("/");
    expect(sanitizeNextPath("/dashboard/accounts")).toBe("/dashboard/accounts");
    expect(sanitizeNextPath("/invoices?status=draft")).toBe(
      "/invoices?status=draft",
    );
  });

  it("uses the first element of an array", () => {
    expect(sanitizeNextPath(["/a", "/b"])).toBe("/a");
  });

  it("blocks absolute and protocol-relative URLs", () => {
    expect(sanitizeNextPath("https://evil.test/steal")).toBe("/");
    expect(sanitizeNextPath("//evil.test/steal")).toBe("/");
  });

  it("blocks backslash tricks", () => {
    expect(sanitizeNextPath("/dashboard\\@evil.test")).toBe("/");
  });

  it("never redirects back into the login flow", () => {
    expect(sanitizeNextPath("/login")).toBe("/");
    expect(sanitizeNextPath("/login?next=/")).toBe("/");
    expect(sanitizeNextPath("/login/reset")).toBe("/");
  });

  it("blocks non-path data", () => {
    expect(sanitizeNextPath("javascript:alert(1)")).toBe("/");
  });
});