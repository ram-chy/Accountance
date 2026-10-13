import { describe, expect, it } from "vitest";

import type { CustomerWritePayload } from "./types";
import {
  CustomerDataError,
  parseCustomer,
  parseCustomerList,
  parseCustomerOrThrow,
  validateCustomerWrite,
} from "./validators";

const customer = {
  id: 4,
  customer_code: "C-001",
  name: "Acme Trading",
  email: "billing@acme.test",
  phone: null,
  address: null,
  city: null,
  state: null,
  country_code: "US",
  tax_identifier: null,
  receivable_account_id: 12,
  is_active: true,
  receivable_account: { id: 12, code: "1100", name: "Accounts Receivable" },
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
};

function payload(overrides: Partial<CustomerWritePayload> = {}): CustomerWritePayload {
  return {
    customer_code: "C-002",
    name: "New Customer",
    email: null,
    phone: null,
    address: null,
    city: null,
    state: null,
    country_code: null,
    tax_identifier: null,
    receivable_account_id: 12,
    ...overrides,
  };
}

describe("parseCustomer", () => {
  it("narrows a complete row including the receivable account ref", () => {
    const parsed = parseCustomer(customer);

    expect(parsed).not.toBeNull();
    expect(parsed?.customer_code).toBe("C-001");
    expect(parsed?.receivable_account?.code).toBe("1100");
  });

  it("accepts a row without the optional relation", () => {
    const parsed = parseCustomer({ ...customer, receivable_account: undefined });

    expect(parsed).not.toBeNull();
    expect(parsed?.receivable_account).toBeNull();
  });

  it("drops a row missing a required identifier", () => {
    expect(parseCustomer({ ...customer, id: undefined })).toBeNull();
    expect(parseCustomer({ ...customer, name: undefined })).toBeNull();
  });

  it("throws through the OrThrow variant for malformed data", () => {
    expect(() => parseCustomerOrThrow({})).toThrow(CustomerDataError);
  });
});

describe("parseCustomerList", () => {
  it("normalizes a flat array and infers no further pages", () => {
    const state = parseCustomerList([customer], 25);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
    expect(state.hasMore).toBe(false);
  });

  it("infers more pages from a full page", () => {
    const full = Array.from({ length: 2 }, (_, index) => ({ ...customer, id: index + 1 }));
    const state = parseCustomerList(full, 2);

    expect(state.hasMore).toBe(true);
  });

  it("drops malformed rows instead of fabricating them", () => {
    const state = parseCustomerList([customer, { id: "nope" }, null], 25);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });
});

describe("validateCustomerWrite", () => {
  it("accepts a well-formed payload", () => {
    expect(validateCustomerWrite(payload())).toEqual({});
  });

  it("requires a code and a name", () => {
    const errors = validateCustomerWrite(payload({ customer_code: " ", name: "" }));

    expect(errors.customer_code).toBeDefined();
    expect(errors.name).toBeDefined();
  });

  it("rejects an invalid code pattern", () => {
    const errors = validateCustomerWrite(payload({ customer_code: "bad code!" }));

    expect(errors.customer_code).toBeDefined();
  });

  it("rejects an invalid email", () => {
    const errors = validateCustomerWrite(payload({ email: "not-an-email" }));

    expect(errors.email).toBeDefined();
  });

  it("rejects a country code that is not two letters", () => {
    const errors = validateCustomerWrite(payload({ country_code: "USA" }));

    expect(errors.country_code).toBeDefined();
  });

  it("requires a receivable account", () => {
    const errors = validateCustomerWrite(payload({ receivable_account_id: null }));

    expect(errors.receivable_account_id).toBeDefined();
  });
});
