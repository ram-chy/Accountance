import { describe, expect, it } from "vitest";

import type { PurchaseBill, PurchaseBillLinePayload, PurchaseBillWritePayload } from "./types";
import {
  parsePurchaseBill,
  parsePurchaseBillLine,
  parsePurchaseBillList,
  validatePurchaseBillWrite,
} from "./validators";

const validLine: PurchaseBillLinePayload = {
  description: "Consulting",
  quantity: "2",
  unit_cost: "100",
  discount: "0",
  tax_rate: "10",
  expense_account_id: 7,
};

const validBill: PurchaseBill = {
  id: 1,
  bill_number: "BILL-000001",
  supplier_id: 3,
  bill_date: "2026-05-01",
  due_date: "2026-05-31",
  status: "POSTED",
  subtotal: "200.0000",
  discount_total: "0.0000",
  tax_total: "20.0000",
  grand_total: "220.0000",
  paid_total: "0.0000",
  balance_due: "220.0000",
  is_overdue: false,
  days_overdue: 0,
  tax_account_id: null,
  journal_id: 9,
  posted_at: "2026-05-01T12:00:00Z",
  notes: null,
  lines: [
    {
      id: 11,
      line_number: 1,
      description: "Consulting",
      quantity: "2.0000",
      unit_cost: "100.0000",
      discount: "0.0000",
      tax_rate: "10.0000",
      line_total: "220.0000",
      tax_amount: "20.0000",
      expense_account_id: 7,
    },
  ],
  supplier: { id: 3, supplier_code: "S-001", name: "Parts Ltd" },
  created_at: "2026-05-01T00:00:00Z",
  updated_at: "2026-05-01T00:00:00Z",
};

describe("parsePurchaseBillLine", () => {
  it("narrows a complete line priced at unit_cost", () => {
    const parsed = parsePurchaseBillLine(validBill.lines[0]);

    expect(parsed).not.toBeNull();
    expect(parsed?.unit_cost).toBe("100.0000");
    expect(parsed?.expense_account_id).toBe(7);
  });

  it("drops a line missing the expense account", () => {
    expect(parsePurchaseBillLine({ ...validBill.lines[0], expense_account_id: undefined })).toBeNull();
  });
});

describe("parsePurchaseBill", () => {
  it("narrows a complete bill including settlement fields", () => {
    const parsed = parsePurchaseBill(validBill);

    expect(parsed).not.toBeNull();
    expect(parsed?.bill_number).toBe("BILL-000001");
    expect(parsed?.balance_due).toBe("220.0000");
    expect(parsed?.lines).toHaveLength(1);
  });

  it("accepts a store response without settlement fields", () => {
    const parsed = parsePurchaseBill({
      ...validBill,
      paid_total: undefined,
      balance_due: undefined,
      is_overdue: undefined,
      days_overdue: undefined,
    });

    expect(parsed).not.toBeNull();
    expect(parsed?.paid_total).toBeUndefined();
  });

  it("drops a bill with an unknown status", () => {
    expect(parsePurchaseBill({ ...validBill, status: "VOID" })).toBeNull();
  });

  it("drops a bill whose supplier ref is malformed", () => {
    expect(parsePurchaseBill({ ...validBill, supplier: { id: 3 } })).toBeNull();
  });
});

describe("parsePurchaseBillList", () => {
  it("narrows a flat array", () => {
    const state = parsePurchaseBillList([validBill], 25);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });

  it("errors when every row is invalid", () => {
    expect(parsePurchaseBillList([{ id: 1 }], 25).status).toBe("error");
  });
});

describe("validatePurchaseBillWrite", () => {
  const base: PurchaseBillWritePayload = {
    supplier_id: 3,
    bill_date: "2026-05-01",
    due_date: "2026-05-31",
    notes: null,
    tax_account_id: null,
    lines: [validLine],
  };

  it("returns no errors for a valid payload", () => {
    expect(validatePurchaseBillWrite(base)).toEqual({});
  });

  it("requires a supplier", () => {
    expect(validatePurchaseBillWrite({ ...base, supplier_id: null }).supplier_id).toBeDefined();
  });

  it("requires the due date on or after the bill date", () => {
    expect(validatePurchaseBillWrite({ ...base, due_date: "2026-04-30" }).due_date).toBeDefined();
  });

  it("requires at least one line", () => {
    expect(validatePurchaseBillWrite({ ...base, lines: [] }).lines).toBeDefined();
  });

  it("rejects a non-positive quantity and a missing expense account", () => {
    const errors = validatePurchaseBillWrite({
      ...base,
      lines: [{ ...validLine, quantity: "0", expense_account_id: null }],
    });

    expect(errors["lines.0.quantity"]).toBeDefined();
    expect(errors["lines.0.expense_account_id"]).toBeDefined();
  });

  it("rejects a discount larger than the line gross", () => {
    const errors = validatePurchaseBillWrite({
      ...base,
      lines: [{ ...validLine, quantity: "1", unit_cost: "10", discount: "11" }],
    });

    expect(errors["lines.0.discount"]).toBeDefined();
  });
});
