import { describe, expect, it } from "vitest";

import {
  UsersDataError,
  parseUser,
  parseUserList,
  parseUserOrThrow,
  toUserQuery,
  validateUserCreate,
  validateUserUpdate,
} from "./validators";

const validUser = {
  id: 1,
  first_name: "Ada",
  last_name: "Lovelace",
  full_name: "Ada Lovelace",
  email: "ada@example.test",
  mobile_no: null,
  email_verified: true,
  is_active: true,
  roles: [{ id: 1, name: "Admin" }],
  last_login_at: null,
  created_at: null,
  updated_at: null,
};

describe("parseUser", () => {
  it("narrows a complete row", () => {
    const user = parseUser(validUser);

    expect(user).not.toBeNull();
    expect(user?.full_name).toBe("Ada Lovelace");
    expect(user?.roles[0]?.name).toBe("Admin");
  });

  it("derives a full name when the server omits one", () => {
    const user = parseUser({ ...validUser, full_name: undefined });

    expect(user?.full_name).toBe("Ada Lovelace");
  });

  it("drops roles that are not shaped like { id, name }", () => {
    const user = parseUser({ ...validUser, roles: [{ id: 1 }, { name: "Admin" }, null] });

    expect(user?.roles).toEqual([]);
  });

  it("drops a row missing an id", () => {
    expect(parseUser({ ...validUser, id: undefined })).toBeNull();
  });

  it("drops a row missing an email", () => {
    expect(parseUser({ ...validUser, email: undefined })).toBeNull();
  });

  it("throws through the OrThrow variant", () => {
    expect(() => parseUserOrThrow({})).toThrow(UsersDataError);
  });
});

describe("parseUserList", () => {
  it("keeps valid rows and skips invalid ones", () => {
    const state = parseUserList([validUser, { id: 2 }, { ...validUser, id: 3 }]);

    expect(state.status).toBe("ready");
    expect(state.items.map((item) => item.id)).toEqual([1, 3]);
  });

  it("errors when the payload is not a list", () => {
    expect(parseUserList(42).status).toBe("error");
  });

  it("errors when no row is valid", () => {
    expect(parseUserList([{ id: 2 }, null]).status).toBe("error");
  });

  it("accepts an empty list", () => {
    const state = parseUserList([]);

    expect(state.status).toBe("ready");
    expect(state.items).toEqual([]);
  });
});

describe("toUserQuery", () => {
  it("always requests the maximum page size", () => {
    expect(toUserQuery({})).toEqual({ per_page: 100 });
  });

  it("folds the tri-state active filter into 1/0", () => {
    expect(toUserQuery({ is_active: true }).is_active).toBe(1);
    expect(toUserQuery({ is_active: false }).is_active).toBe(0);
    expect(toUserQuery({}).is_active).toBeUndefined();
  });

  it("trims and drops blank strings", () => {
    expect(toUserQuery({ search: "  ", role: "" })).toEqual({ per_page: 100 });
  });
});

describe("validateUserCreate", () => {
  function payload(overrides: Record<string, unknown> = {}) {
    return {
      first_name: "Ada",
      last_name: "Lovelace",
      email: "ada@example.test",
      mobile_no: null,
      password: "Str0ng!pass",
      roles: ["Admin"],
      ...overrides,
    } as Parameters<typeof validateUserCreate>[0];
  }

  it("accepts a valid payload", () => {
    expect(validateUserCreate(payload())).toEqual({});
  });

  it("requires at least one role", () => {
    expect(validateUserCreate(payload({ roles: [] })).roles).toBeDefined();
  });

  it("rejects an unknown role", () => {
    expect(validateUserCreate(payload({ roles: ["Wizard"] })).roles).toBeDefined();
  });

  it("enforces the password policy", () => {
    expect(validateUserCreate(payload({ password: "short" })).password).toBeDefined();
  });

  it("rejects a malformed mobile number", () => {
    expect(validateUserCreate(payload({ mobile_no: "12" })).mobile_no).toBeDefined();
  });
});

describe("validateUserUpdate", () => {
  it("accepts an empty payload", () => {
    expect(validateUserUpdate({})).toEqual({});
  });

  it("validates roles only when present", () => {
    expect(validateUserUpdate({}).roles).toBeUndefined();
    expect(validateUserUpdate({ roles: [] }).roles).toBeDefined();
  });

  it("validates a password only when non-empty", () => {
    expect(validateUserUpdate({ password: "" }).password).toBeUndefined();
    expect(validateUserUpdate({ password: "weak" }).password).toBeDefined();
  });
});
