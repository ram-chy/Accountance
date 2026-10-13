import { describe, expect, it } from "vitest";

import type { Supplier } from "./types";
import { parseSupplier, parseSupplierList, validateSupplierWrite } from "./validators";

const validSupplier: Supplier = {
  id: 3,
  supplier_code: "S-001",
  name: "Parts Ltd",
  email: "orders@parts.example",
  phone: null,
  address: null,
  city: null,
  state: null,
  country_code: "US",
  tax_identifier: null,
  payable_account_id: 20,
  is_active: true,
  payable_account: { id: 20, code: "2000", name: "Accounts payable" },
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
};

describe("parseSupplier", () => {
  it("narrows a complete supplier row", () => {
    const parsed = parseSupplier(validSupplier);

    expect(parsed).not.toBeNull();
    expect(parsed?.supplier_code).toBe("S-001");
    expect(parsed?.payable_account?.code).toBe("2000");
  });

  it("accepts a null payable_account", () => {
    expect(parseSupplier({ ...validSupplier, payable_account: null })).not.toBeNull();
  });

  it("drops a row missing the payable account id", () => {
    expect(parseSupplier({ ...validSupplier, payable_account_id: undefined })).toBeNull();
  });

  it("drops a row whose payable_account is malformed", () => {
    expect(parseSupplier({ ...validSupplier, payable_account: { id: 20 } })).toBeNull();
  });

  it("drops a non-object", () => {
    expect(parseSupplier("nope")).toBeNull();
    expect(parseSupplier(null)).toBeNull();
  });
});

describe("parseSupplierList", () => {
  it("narrows a flat array and infers hasMore from a full page", () => {
    const state = parseSupplierList([validSupplier], 25);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
    expect(state.hasMore).toBe(false);
  });

  it("errors when every row is invalid", () => {
    const state = parseSupplierList([{ id: 1 }], 25);

    expect(state.status).toBe("error");
  });

  it("accepts the envelope list shape", () => {
    const state = parseSupplierList({ success: true, data: [validSupplier] }, 25);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });
});

describe("validateSupplierWrite", () => {
  const base = {
    supplier_code: "S-001",
    name: "Parts Ltd",
    email: null,
    phone: null,
    address: null,
    city: null,
    state: null,
    country_code: null,
    tax_identifier: null,
    payable_account_id: 20,
  };

  it("returns no errors for a valid payload", () => {
    expect(validateSupplierWrite(base)).toEqual({});
  });

  it("requires a code and enforces its pattern", () => {
    expect(validateSupplierWrite({ ...base, supplier_code: "" }).supplier_code).toBeDefined();
    expect(validateSupplierWrite({ ...base, supplier_code: "-bad" }).supplier_code).toBeDefined();
  });

  it("requires a name", () => {
    expect(validateSupplierWrite({ ...base, name: "  " }).name).toBeDefined();
  });

  it("rejects a malformed email", () => {
    expect(validateSupplierWrite({ ...base, email: "not-an-email" }).email).toBeDefined();
  });

  it("rejects a country code that is not two letters", () => {
    expect(validateSupplierWrite({ ...base, country_code: "USA" }).country_code).toBeDefined();
  });

  it("requires a payable account", () => {
    expect(validateSupplierWrite({ ...base, payable_account_id: null }).payable_account_id).toBeDefined();
  });
});
