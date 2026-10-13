import { describe, expect, it } from "vitest";

import {
  CustomerReceiptDataError,
  decimalsEqual,
  parseCustomerReceipt,
  parseCustomerReceiptOrThrow,
  validateCustomerReceiptWrite,
} from "./validators";
import type { CustomerReceiptWritePayload } from "./types";

const receipt = {
  id: 5,
  receipt_number: "RCP-0005",
  customer_id: 4,
  receipt_date: "2026-06-30",
  amount: "600.0000",
  status: "POSTED",
  payment_account_id: 2,
  reference: "WIRE-1",
  notes: null,
  allocations: [
    {
      id: 1,
      customer_receipt_id: 5,
      sales_invoice_id: 11,
      amount: "600.0000",
      sales_invoice: { id: 11, invoice_number: "INV-011", status: "POSTED", grand_total: "1000.0000", balance_due: "600.0000" },
    },
  ],
  customer: { id: 4, customer_code: "C-001", name: "Acme" },
  journal_id: 9,
  posted_at: "2026-06-30T10:00:00Z",
  created_at: "2026-06-30T10:00:00Z",
  updated_at: "2026-06-30T10:00:00Z",
};

function payload(overrides: Partial<CustomerReceiptWritePayload> = {}): CustomerReceiptWritePayload {
  return {
    customer_id: 4,
    receipt_date: "2026-06-30",
    amount: "600.0000",
    payment_account_id: 2,
    reference: null,
    notes: null,
    allocations: [{ sales_invoice_id: 11, amount: "600.0000" }],
    ...overrides,
  };
}

describe("parseCustomerReceipt", () => {
  it("narrows a complete receipt with allocations", () => {
    const parsed = parseCustomerReceipt(receipt);

    expect(parsed).not.toBeNull();
    expect(parsed?.receipt_number).toBe("RCP-0005");
    expect(parsed?.allocations[0].sales_invoice?.invoice_number).toBe("INV-011");
  });

  it("drops a receipt with an unknown status", () => {
    expect(parseCustomerReceipt({ ...receipt, status: "CANCELLED" })).toBeNull();
  });

  it("throws through the OrThrow variant for a malformed body", () => {
    expect(() => parseCustomerReceiptOrThrow({})).toThrow(CustomerReceiptDataError);
  });
});

describe("decimalsEqual", () => {
  it("compares decimals exactly regardless of scale", () => {
    expect(decimalsEqual("600", "600.0000")).toBe(true);
    expect(decimalsEqual("600.0001", "600.0000")).toBe(false);
  });
});

describe("validateCustomerReceiptWrite", () => {
  it("accepts a well-formed receipt", () => {
    expect(validateCustomerReceiptWrite(payload())).toEqual({});
  });

  it("requires a customer and a payment account", () => {
    const errors = validateCustomerReceiptWrite(payload({ customer_id: null, payment_account_id: null }));

    expect(errors.customer_id).toBeDefined();
    expect(errors.payment_account_id).toBeDefined();
  });

  it("requires a positive amount and at least one allocation", () => {
    const errors = validateCustomerReceiptWrite(payload({ amount: "0", allocations: [] }));

    expect(errors.amount).toBeDefined();
    expect(errors.allocations).toBeDefined();
  });

  it("requires the allocations to total exactly the amount", () => {
    const errors = validateCustomerReceiptWrite(
      payload({ amount: "600.0000", allocations: [{ sales_invoice_id: 11, amount: "500.0000" }] }),
    );

    expect(errors.allocations).toBe("Allocations must total exactly the receipt amount.");
  });

  it("rejects the same invoice allocated twice", () => {
    const errors = validateCustomerReceiptWrite(
      payload({
        amount: "600.0000",
        allocations: [
          { sales_invoice_id: 11, amount: "300.0000" },
          { sales_invoice_id: 11, amount: "300.0000" },
        ],
      }),
    );

    expect(errors["allocations.1.sales_invoice_id"]).toBeDefined();
  });

  it("rejects a badly formatted receipt date", () => {
    const errors = validateCustomerReceiptWrite(payload({ receipt_date: "30/06/2026" }));

    expect(errors.receipt_date).toBeDefined();
  });
});
