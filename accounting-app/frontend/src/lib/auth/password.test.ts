import { describe, expect, it } from "vitest";

import {
  PASSWORD_MIN_LENGTH,
  PASSWORD_POLICY_HINT,
  validatePasswordStrength,
} from "./password";

describe("validatePasswordStrength", () => {
  it("accepts a password meeting every rule", () => {
    expect(validatePasswordStrength("Str0ng!pass")).toBeNull();
  });

  it("rejects a password shorter than the minimum", () => {
    expect(validatePasswordStrength("Ab1!")).toContain("at least");
  });

  it("requires a lowercase letter", () => {
    expect(validatePasswordStrength("ABC123!@#")).toBe(
      "Password must contain a lowercase letter.",
    );
  });

  it("requires an uppercase letter", () => {
    expect(validatePasswordStrength("abc123!@#")).toBe(
      "Password must contain an uppercase letter.",
    );
  });

  it("requires a number", () => {
    expect(validatePasswordStrength("Abcdef!@#")).toBe(
      "Password must contain a number.",
    );
  });

  it("requires a symbol", () => {
    expect(validatePasswordStrength("Abcdefg1")).toBe(
      "Password must contain a symbol.",
    );
  });

  it("exposes the minimum length in the hint", () => {
    expect(PASSWORD_POLICY_HINT).toContain(String(PASSWORD_MIN_LENGTH));
  });
});
