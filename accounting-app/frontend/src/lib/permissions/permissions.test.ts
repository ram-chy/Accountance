import { describe, expect, it } from "vitest";

import type { SessionUser, SessionUserRole } from "@/lib/auth/types";
import {
  collectPermissions,
  hasAllPermissions,
  hasAnyPermission,
  hasPermission,
} from "./permissions";

type MalformedSessionUserRole = SessionUserRole & {
  permissions?: SessionUserRole["permissions"];
};

function makeUser(roles: SessionUser["roles"]): SessionUser {
  return {
    id: 1,
    first_name: "Ada",
    last_name: "Lovelace",
    full_name: "Ada Lovelace",
    email: "ada@example.test",
    mobile_no: null,
    email_verified: true,
    is_active: true,
    roles,
    last_login_at: null,
    created_at: null,
    updated_at: null,
  };
}

describe("collectPermissions", () => {
  it("flattens roles[].permissions[].name", () => {
    const user = makeUser([
      {
        id: 1,
        name: "Bookkeeper",
        permissions: [{ id: 1, name: "accounts.view" }],
      },
      {
        id: 2,
        name: "Manager",
        permissions: [
          { id: 2, name: "accounts.view" },
          { id: 3, name: "companies.switch" },
        ],
      },
    ]);

    const permissions = collectPermissions(user);

    expect([...permissions].sort()).toEqual([
      "accounts.view",
      "companies.switch",
    ]);
  });

  it("returns an empty set for null/undefined users (fail closed)", () => {
    expect([...collectPermissions(null)].length).toBe(0);
    expect([...collectPermissions(undefined)].length).toBe(0);
  });

  it("tolerates roles or permissions lacking data", () => {
    const user = makeUser([
      { id: 1, name: "Broken" } as MalformedSessionUserRole,
    ]);

    expect([...collectPermissions(user)].length).toBe(0);
  });
});

describe("hasPermission", () => {
  it("grants an explicitly held permission", () => {
    expect(
      hasPermission(new Set(["accounts.view"]), "accounts.view"),
    ).toBe(true);
  });

  it("denies an unheld permission", () => {
    expect(
      hasPermission(new Set(["accounts.view"]), "users.create"),
    ).toBe(false);
  });

  it("grants everything when the wildcard role permission is present", () => {
    const permissions = new Set(["*"]);
    expect(hasPermission(permissions, "users.delete")).toBe(true);
    expect(hasPermission(permissions, "anything.at.all")).toBe(true);
  });

  it("fails closed on an empty required permission", () => {
    expect(hasPermission(new Set(["*"]), "")).toBe(false);
  });
});

describe("hasAnyPermission / hasAllPermission", () => {
  it("true when ANY required permission is held", () => {
    const permissions = new Set(["a.view"]);

    expect(hasAnyPermission(permissions, ["a.view", "missing"])).toBe(true);
    expect(hasAnyPermission(permissions, ["missing"])).toBe(false);
  });

  it("true when ALL required permissions are held", () => {
    const permissions = new Set(["a.view", "a.edit"]);

    expect(hasAllPermissions(permissions, ["a.view", "a.edit"])).toBe(true);
    expect(hasAllPermissions(permissions, ["a.view", "missing"])).toBe(false);
  });

  it("treats empty required lists as vacuously true", () => {
    expect(hasAnyPermission(new Set(), [])).toBe(true);
    expect(hasAllPermissions(new Set(), [])).toBe(true);
  });
});