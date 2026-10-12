import { describe, expect, it } from "vitest";

import {
  AccountsDataError,
  parseAccount,
  parseAccountList,
  parseAccountOrThrow,
  parseAccountTypes,
  validateAccountWrite,
} from "./validators";
import type { AccountWritePayload } from "./types";

// Local fixture mirroring the verified `AccountResource`.
const account = {
  id: 1,
  code: "1000",
  name: "Cash",
  account_type: "ASSET",
  cash_bank_kind: "CASH",
  is_cash_bank_account: true,
  normal_balance: "DEBIT",
  is_contra: false,
  parent_id: null,
  description: null,
  is_active: true,
  is_system: false,
  has_journal_history: false,
  created_at: "2026-01-01T00:00:00+00:00",
  updated_at: "2026-01-01T00:00:00+00:00",
};

describe("parseAccount", () => {
  it("narrows a valid row", () => {
    expect(parseAccount(account)).toEqual({
      id: 1,
      code: "1000",
      name: "Cash",
      account_type: "ASSET",
      cash_bank_kind: "CASH",
      is_cash_bank_account: true,
      normal_balance: "DEBIT",
      is_contra: false,
      parent_id: null,
      description: null,
      is_active: true,
      is_system: false,
      has_journal_history: false,
      created_at: "2026-01-01T00:00:00+00:00",
      updated_at: "2026-01-01T00:00:00+00:00",
    });
  });

  it("returns null for a non-record", () => {
    expect(parseAccount(null)).toBeNull();
    expect(parseAccount("1000")).toBeNull();
  });

  it("returns null when a required identity field is missing", () => {
    expect(parseAccount({ ...account, id: undefined })).toBeNull();
    expect(parseAccount({ ...account, code: 1000 })).toBeNull();
    expect(parseAccount({ ...account, normal_balance: "SIDEWAYS" })).toBeNull();
    expect(parseAccount({ ...account, account_type: "CASH" })).toBeNull();
  });

  it("returns null when a lifecycle boolean is missing", () => {
    expect(parseAccount({ ...account, is_active: undefined })).toBeNull();
    expect(parseAccount({ ...account, is_system: "true" })).toBeNull();
    expect(
      parseAccount({ ...account, has_journal_history: undefined }),
    ).toBeNull();
  });

  it("tolerates a nullable cash/bank kind and parent", () => {
    const parsed = parseAccount({
      ...account,
      cash_bank_kind: null,
      parent_id: 5,
      is_contra: true,
      normal_balance: "CREDIT",
    });

    expect(parsed?.cash_bank_kind).toBeNull();
    expect(parsed?.parent_id).toBe(5);
    expect(parsed?.is_contra).toBe(true);
    expect(parsed?.normal_balance).toBe("CREDIT");
  });

  it("treats a non-boolean is_contra as false", () => {
    expect(parseAccount({ ...account, is_contra: "yes" })?.is_contra).toBe(false);
  });
});

describe("parseAccountList", () => {
  it("narrows a flat array and infers hasMore from the page size", () => {
    const state = parseAccountList([account], 50);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
    expect(state.hasMore).toBe(false);
  });

  it("reports hasMore when a full page is returned", () => {
    const state = parseAccountList([account, { ...account, id: 2 }], 2);
    expect(state.hasMore).toBe(true);
  });

  it("skips invalid rows but keeps the valid ones", () => {
    const state = parseAccountList(
      [account, { code: "broken" }, { ...account, id: 3, name: "Sales" }],
      50,
    );

    expect(state.items.map((item) => item.id)).toEqual([1, 3]);
  });

  it("errors when the payload is not a list and no row is valid", () => {
    expect(parseAccountList("nope", 50).status).toBe("error");
    expect(parseAccountList([{ code: "broken" }], 50).status).toBe("error");
  });
});

describe("parseAccountTypes", () => {
  it("narrows the type reference list", () => {
    const types = parseAccountTypes([
      { value: "ASSET", normal_balance: "DEBIT" },
      { value: "REVENUE", normal_balance: "CREDIT" },
      { value: "NOPE", normal_balance: "CREDIT" },
      "broken",
    ]);

    expect(types).toEqual([
      { value: "ASSET", normal_balance: "DEBIT" },
      { value: "REVENUE", normal_balance: "CREDIT" },
    ]);
  });

  it("returns an empty list for a non-array payload", () => {
    expect(parseAccountTypes(null)).toEqual([]);
  });
});

describe("parseAccountOrThrow", () => {
  it("returns the account for a valid payload", () => {
    expect(parseAccountOrThrow(account).id).toBe(1);
  });

  it("throws AccountsDataError for an invalid payload", () => {
    expect(() => parseAccountOrThrow({})).toThrow(AccountsDataError);
  });
});

describe("validateAccountWrite", () => {
  const base: AccountWritePayload = {
    code: "1000",
    name: "Cash",
    account_type: "ASSET",
    cash_bank_kind: null,
    normal_balance: null,
    description: null,
  };

  it("accepts a valid payload", () => {
    expect(validateAccountWrite(base)).toEqual({});
  });

  it("requires a code and a name", () => {
    expect(validateAccountWrite({ ...base, code: "  " }).code).toBeDefined();
    expect(validateAccountWrite({ ...base, name: "" }).name).toBeDefined();
  });

  it("enforces the code pattern and length", () => {
    expect(validateAccountWrite({ ...base, code: "1000/2" }).code).toBeDefined();
    expect(
      validateAccountWrite({ ...base, code: "A".repeat(51) }).code,
    ).toBeDefined();
  });

  it("rejects unknown enum values", () => {
    expect(
      validateAccountWrite({
        ...base,
        account_type: "CASH" as AccountWritePayload["account_type"],
      }).account_type,
    ).toBeDefined();
    expect(
      validateAccountWrite({
        ...base,
        cash_bank_kind: "CHEQUE" as AccountWritePayload["cash_bank_kind"],
      }).cash_bank_kind,
    ).toBeDefined();
    expect(
      validateAccountWrite({
        ...base,
        normal_balance: "MIDDLE" as AccountWritePayload["normal_balance"],
      }).normal_balance,
    ).toBeDefined();
  });

  it("rejects an over-long description", () => {
    expect(
      validateAccountWrite({ ...base, description: "x".repeat(1001) })
        .description,
    ).toBeDefined();
  });
});