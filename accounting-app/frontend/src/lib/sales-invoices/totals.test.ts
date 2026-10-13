import { describe, expect, it } from "vitest";

import type { SalesInvoiceLinePayload } from "./types";
import {
  addDecimals,
  computeInvoiceTotals,
  computeLineTotals,
  divideByHundred,
  multiplyDecimals,
  subtractDecimals,
} from "./totals";

/**
 * The preview arithmetic must mirror `DocumentCalculator`: gross = qty × price,
 * net = gross − discount, tax = net × rate / 100, total = net + tax. These
 * cases pin exact decimal behaviour that a float implementation would miss.
 */

function line(overrides: Partial<SalesInvoiceLinePayload> = {}): SalesInvoiceLinePayload {
  return {
    description: null,
    quantity: "1",
    unit_price: "0",
    discount: "0",
    tax_rate: "0",
    revenue_account_id: 1,
    ...overrides,
  };
}

describe("decimal helpers", () => {
  it("multiplies without binary floating-point error", () => {
    expect(multiplyDecimals("0.1", "0.2")).toBe("0.02");
  });

  it("adds and subtracts across differing scales", () => {
    expect(addDecimals("1.5", "2.25")).toBe("3.75");
    expect(subtractDecimals("10", "0.001")).toBe("9.999");
  });

  it("shifts the decimal point two places left for a percentage", () => {
    expect(divideByHundred("18")).toBe("0.18");
    expect(divideByHundred("0.5")).toBe("0.005");
  });
});

describe("computeLineTotals", () => {
  it("applies discount then tax to the net amount", () => {
    const totals = computeLineTotals(line({ quantity: "2", unit_price: "100", discount: "20", tax_rate: "10" }));

    expect(totals.gross).toBe("200");
    expect(totals.net).toBe("180");
    expect(totals.taxAmount).toBe("18.00");
    expect(totals.lineTotal).toBe("198");
  });

  it("treats an empty input as zero", () => {
    const totals = computeLineTotals(line({ quantity: "", unit_price: "" }));

    expect(totals.gross).toBe("0");
    expect(totals.lineTotal).toBe("0");
  });
});

describe("computeInvoiceTotals", () => {
  it("sums each column across lines", () => {
    const totals = computeInvoiceTotals([
      line({ quantity: "2", unit_price: "100", discount: "20", tax_rate: "10" }),
      line({ quantity: "1", unit_price: "50", discount: "0", tax_rate: "10" }),
    ]);

    expect(totals.subtotal).toBe("250");
    expect(totals.discountTotal).toBe("20");
    expect(totals.taxTotal).toBe("23");
    expect(totals.grandTotal).toBe("253");
  });

  it("returns zero totals for no lines", () => {
    const totals = computeInvoiceTotals([]);

    expect(totals.grandTotal).toBe("0");
  });
});
