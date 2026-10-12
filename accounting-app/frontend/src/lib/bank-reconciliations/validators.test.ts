import { describe, expect, it } from "vitest";

import type { BankReconciliation, BankReconciliationMovement } from "./types";
import {
  BankReconciliationsDataError,
  isZeroDecimalString,
  parseBankReconciliation,
  parseBankReconciliationItemId,
  parseBankReconciliationList,
  parseBankReconciliationMovement,
  parseBankReconciliationMovements,
  parseBankReconciliationOrThrow,
  validateAddReconciliationItem,
} from "./validators";

const reconciliation: BankReconciliation = {
  id: 3,
  company_id: 1,
  bank_account_id: 7,
  account_id: 20,
  from_date: "2026-04-01",
  to_date: "2026-04-30",
  status: "IN_PROGRESS",
  statement_opening_balance: "1000.0000",
  statement_closing_balance: "1200.0000",
  ledger_opening: "1000.0000",
  ledger_closing: "1150.0000",
  period_debits: "300.0000",
  period_credits: "150.0000",
  cleared_debits: "200.0000",
  cleared_credits: "100.0000",
  uncleared_debits: "100.0000",
  uncleared_credits: "50.0000",
  reconciled_balance: "1200.0000",
  difference: "0.0000",
  total_movements: 4,
  cleared_movements: 2,
  uncleared_movements: 2,
  is_reconciled: false,
  previous_reconciled_closing: null,
  opening_balance_difference: null,
  notes: null,
  completed_at: null,
  reopened_at: null,
  created_at: "2026-05-01T09:00:00+00:00",
  updated_at: "2026-05-01T09:00:00+00:00",
};

const movement: BankReconciliationMovement = {
  journal_id: 11,
  journal_line_id: 42,
  journal_number: "JV-000011",
  journal_date: "2026-04-15",
  reference: "INV-1",
  description: "Receipt",
  source_type: "cash_bank_transaction",
  source_id: 5,
  debit: "100.0000",
  credit: null,
  amount: "100.0000",
  direction: "DEBIT",
  cleared: true,
};

describe("parseBankReconciliation", () => {
  it("narrows a valid row", () => {
    expect(parseBankReconciliation(reconciliation)).toEqual(reconciliation);
  });

  it("returns null for a non-record", () => {
    expect(parseBankReconciliation(null)).toBeNull();
    expect(parseBankReconciliation("nope")).toBeNull();
  });

  it("returns null when a required identity field is missing", () => {
    expect(parseBankReconciliation({ ...reconciliation, id: undefined })).toBeNull();
    expect(parseBankReconciliation({ ...reconciliation, status: "SETTLED" })).toBeNull();
    expect(parseBankReconciliation({ ...reconciliation, from_date: undefined })).toBeNull();
    expect(parseBankReconciliation({ ...reconciliation, to_date: 5 })).toBeNull();
  });

  it("degrades missing monetary figures and timestamps to null", () => {
    const parsed = parseBankReconciliation({
      ...reconciliation,
      difference: undefined,
      reconciled_balance: 0,
      notes: 9,
    });

    expect(parsed?.difference).toBeNull();
    expect(parsed?.reconciled_balance).toBeNull();
    expect(parsed?.notes).toBeNull();
  });

  it("defaults is_reconciled when the flag is absent but the status is settled", () => {
    const parsed = parseBankReconciliation({
      ...reconciliation,
      status: "RECONCILED",
      is_reconciled: undefined,
    });

    expect(parsed?.is_reconciled).toBe(true);
  });
});

describe("parseBankReconciliationList", () => {
  it("narrows a flat array", () => {
    const state = parseBankReconciliationList([reconciliation], 25);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });

  it("reports hasMore for a full page", () => {
    const state = parseBankReconciliationList(
      [reconciliation, { ...reconciliation, id: 4 }],
      2,
    );

    expect(state.hasMore).toBe(true);
  });

  it("skips invalid rows but keeps the valid ones", () => {
    const state = parseBankReconciliationList(
      [reconciliation, { status: "DRAFT" }, { ...reconciliation, id: 4 }],
      25,
    );

    expect(state.items.map((item) => item.id)).toEqual([3, 4]);
  });

  it("errors when the payload is not a list", () => {
    expect(parseBankReconciliationList("nope", 25).status).toBe("error");
  });
});

describe("parseBankReconciliationOrThrow", () => {
  it("throws BankReconciliationsDataError for an invalid payload", () => {
    expect(() => parseBankReconciliationOrThrow({})).toThrow(BankReconciliationsDataError);
  });

  it("returns the reconciliation for a valid payload", () => {
    expect(parseBankReconciliationOrThrow(reconciliation).id).toBe(3);
  });
});

describe("parseBankReconciliationMovement", () => {
  it("narrows a valid movement", () => {
    expect(parseBankReconciliationMovement(movement)).toEqual(movement);
  });

  it("returns null when a required field is missing", () => {
    expect(parseBankReconciliationMovement({ ...movement, journal_line_id: undefined })).toBeNull();
    expect(parseBankReconciliationMovement({ ...movement, direction: "SIDEWAYS" })).toBeNull();
    expect(parseBankReconciliationMovement({ ...movement, cleared: "yes" })).toBeNull();
  });
});

describe("parseBankReconciliationMovements", () => {
  it("narrows a flat array", () => {
    const state = parseBankReconciliationMovements([movement, { cleared: true }]);

    expect(state.status).toBe("ready");
    if (state.status === "ready") {
      expect(state.items.map((item) => item.journal_line_id)).toEqual([42]);
    }
  });

  it("accepts a data envelope", () => {
    const state = parseBankReconciliationMovements({ data: [movement] });

    expect(state.status).toBe("ready");
  });

  it("errors on a non-list payload", () => {
    expect(parseBankReconciliationMovements({ nope: true }).status).toBe("error");
  });
});

describe("parseBankReconciliationItemId", () => {
  it("reads the created item id", () => {
    expect(parseBankReconciliationItemId({ id: 88 })).toBe(88);
  });

  it("returns null when the id is absent or malformed", () => {
    expect(parseBankReconciliationItemId({})).toBeNull();
    expect(parseBankReconciliationItemId({ id: "x" })).toBeNull();
    expect(parseBankReconciliationItemId(null)).toBeNull();
  });
});

describe("validateAddReconciliationItem", () => {
  it("accepts a positive integer line id", () => {
    expect(validateAddReconciliationItem({ journal_line_id: 42 })).toEqual({});
  });

  it("rejects a missing, zero or non-integer line id", () => {
    expect(validateAddReconciliationItem({ journal_line_id: 0 }).journal_line_id).toBeDefined();
    expect(validateAddReconciliationItem({ journal_line_id: 1.5 }).journal_line_id).toBeDefined();
  });

  it("rejects notes longer than 5000 characters", () => {
    const long = "x".repeat(5001);
    expect(validateAddReconciliationItem({ journal_line_id: 1, notes: long }).notes).toBeDefined();
  });
});

describe("isZeroDecimalString", () => {
  it("is true only for an exact zero", () => {
    expect(isZeroDecimalString("0")).toBe(true);
    expect(isZeroDecimalString("0.0000")).toBe(true);
    expect(isZeroDecimalString("-0.00")).toBe(true);
  });

  it("is false for a non-zero amount or non-decimal input", () => {
    expect(isZeroDecimalString("0.0001")).toBe(false);
    expect(isZeroDecimalString("1200.0000")).toBe(false);
    expect(isZeroDecimalString(null)).toBe(false);
    expect(isZeroDecimalString("abc")).toBe(false);
  });
});
