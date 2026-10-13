import { describe, expect, it } from "vitest";

import {
  addDecimals,
  computeBillTotals,
  computeLineTotals,
  divideByHundred,
  multiplyDecimals,
  subtractDecimals,
} from "./totals";
import type { PurchaseBillLinePayload } from "./types";

describe("multiplyDecimals", () => {
  it("multiplies exactly without floating point error", () => {
    expect(multiplyDecimals("0.1", "0.2")).toBe("0.02");
    expect(multiplyDecimals("3", "2.5")).toBe("7.5");
  });
});

describe("addDecimals and subtractDecimals", () => {
  it("adds with the wider scale", () => {
    expect(addDecimals("1.5", "2.25")).toBe("3.75");
  });

  it("subtracts to a negative result", () => {
    expect(subtractDecimals("1", "2.5")).toBe("-1.5");
  });
});

describe("divideByHundred", () => {
  it("shifts the decimal point two places left", () => {
    expect(divideByHundred("150")).toBe("1.50");
    expect(divideByHundred("5")).toBe("0.05");
  });
});

describe("computeLineTotals", () => {
  it("applies gross - discount + tax", () => {
    const line: PurchaseBillLinePayload = {
      description: null,
      quantity: "10",
      unit_cost: "2",
      discount: "5",
      tax_rate: "10",
      expense_account_id: 1,
    };

    const totals = computeLineTotals(line);

    expect(totals.gross).toBe("20");
    expect(totals.net).toBe("15");
    expect(totals.taxAmount).toBe("1.50");
    expect(totals.lineTotal).toBe("16.5");
  });

  it("treats blank fields as zero", () => {
    const line: PurchaseBillLinePayload = {
      description: null,
      quantity: "2",
      unit_cost: "3",
      discount: "",
      tax_rate: "",
      expense_account_id: 1,
    };

    expect(computeLineTotals(line).lineTotal).toBe("6");
  });
});

describe("computeBillTotals", () => {
  it("sums lines into subtotal, discount, tax and grand total", () => {
    const lines: PurchaseBillLinePayload[] = [
      { description: null, quantity: "1", unit_cost: "100", discount: "10", tax_rate: "10", expense_account_id: 1 },
      { description: null, quantity: "2", unit_cost: "50", discount: "0", tax_rate: "0", expense_account_id: 1 },
    ];

    const totals = computeBillTotals(lines);

    expect(totals.subtotal).toBe("200");
    expect(totals.discountTotal).toBe("10");
    expect(totals.taxTotal).toBe("9");
    expect(totals.grandTotal).toBe("199");
  });

  it("returns zeros for no lines", () => {
    expect(computeBillTotals([])).toEqual({
      subtotal: "0",
      discountTotal: "0",
      taxTotal: "0",
      grandTotal: "0",
    });
  });
});
