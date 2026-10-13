import { describe, expect, it } from "vitest";

import type { SupplierPayment, SupplierPaymentWritePayload } from "./types";
import {
  decimalsEqual,
  parseSupplierPayment,
  parseSupplierPaymentList,
  validateSupplierPaymentWrite,
} from "./validators";

const validPayment: SupplierPayment = {
  id: 1,
  payment_number: "PAY-000001",
  supplier_id: 3,
  payment_date: "2026-05-02",
  amount: "600.0000",
  status: "POSTED",
  payment_account_id: 2,
  reference: null,
  notes: null,
  allocations: [
    {
      id: 9,
      supplier_payment_id: 1,
      purchase_bill_id: 11,
      amount: "600.0000",
      purchase_bill: { id: 11, bill_number: "BILL-000011", status: "PAID", balance_due: "0.0000" },
    },
  ],
  supplier: { id: 3, supplier_code: "S-001", name: "Parts Ltd" },
  journal_id: 4,
  posted_at: "2026-05-02T09:00:00Z",
  created_at: "2026-05-02T00:00:00Z",
  updated_at: "2026-05-02T00:00:00Z",
};

describe("parseSupplierPayment", () => {
  it("narrows a complete payment with allocations", () => {
    const parsed = parseSupplierPayment(validPayment);

    expect(parsed).not.toBeNull();
    expect(parsed?.payment_number).toBe("PAY-000001");
    expect(parsed?.allocations[0].purchase_bill?.bill_number).toBe("BILL-000011");
  });

  it("accepts a payment with no supplier ref and no allocations", () => {
    const parsed = parseSupplierPayment({ ...validPayment, supplier: null, allocations: [] });

    expect(parsed).not.toBeNull();
    expect(parsed?.allocations).toEqual([]);
  });

  it("drops a payment with an unknown status", () => {
    expect(parseSupplierPayment({ ...validPayment, status: "PARTIALLY_PAID" })).toBeNull();
  });

  it("drops an allocation missing its amount", () => {
    const parsed = parseSupplierPayment({
      ...validPayment,
      allocations: [{ id: 9, supplier_payment_id: 1, purchase_bill_id: 11 }],
    });

    expect(parsed).not.toBeNull();
    expect(parsed?.allocations).toEqual([]);
  });
});

describe("parseSupplierPaymentList", () => {
  it("narrows a flat array", () => {
    const state = parseSupplierPaymentList([validPayment], 25);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });

  it("errors when every row is invalid", () => {
    expect(parseSupplierPaymentList([{ id: 1 }], 25).status).toBe("error");
  });
});

describe("decimalsEqual", () => {
  it("treats trailing-zero differences as equal", () => {
    expect(decimalsEqual("600", "600.0000")).toBe(true);
    expect(decimalsEqual("600.01", "600.1")).toBe(false);
  });
});

describe("validateSupplierPaymentWrite", () => {
  const base: SupplierPaymentWritePayload = {
    supplier_id: 3,
    payment_date: "2026-05-02",
    amount: "600",
    payment_account_id: 2,
    reference: null,
    notes: null,
    allocations: [{ purchase_bill_id: 11, amount: "600" }],
  };

  it("returns no errors for a valid payload", () => {
    expect(validateSupplierPaymentWrite(base)).toEqual({});
  });

  it("requires a positive amount", () => {
    expect(validateSupplierPaymentWrite({ ...base, amount: "0" }).amount).toBeDefined();
  });

  it("requires at least one allocation", () => {
    expect(validateSupplierPaymentWrite({ ...base, allocations: [] }).allocations).toBeDefined();
  });

  it("requires allocations to total exactly the amount", () => {
    const errors = validateSupplierPaymentWrite({
      ...base,
      allocations: [{ purchase_bill_id: 11, amount: "500" }],
    });

    expect(errors.allocations).toBeDefined();
  });

  it("rejects the same bill allocated twice", () => {
    const errors = validateSupplierPaymentWrite({
      ...base,
      amount: "600",
      allocations: [
        { purchase_bill_id: 11, amount: "300" },
        { purchase_bill_id: 11, amount: "300" },
      ],
    });

    expect(errors["allocations.1.purchase_bill_id"]).toBeDefined();
  });

  it("requires a payment account", () => {
    expect(validateSupplierPaymentWrite({ ...base, payment_account_id: null }).payment_account_id).toBeDefined();
  });
});
