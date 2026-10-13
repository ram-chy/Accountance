import { describe, expect, it } from "vitest";

import { computeNoteLineTotals, computeNoteTotals } from "./totals";
import type { CreditDebitNoteLinePayload } from "./types";

function line(overrides: Partial<CreditDebitNoteLinePayload> = {}): CreditDebitNoteLinePayload {
  return {
    description: null,
    quantity: "1",
    unit_price: "100",
    discount: "0",
    tax_rate: "0",
    account_id: 1,
    source_line_id: null,
    ...overrides,
  };
}

describe("computeNoteLineTotals", () => {
  it("applies gross - discount + tax", () => {
    const totals = computeNoteLineTotals(line({ quantity: "10", unit_price: "2", discount: "5", tax_rate: "10" }));

    expect(totals.gross).toBe("20");
    expect(totals.net).toBe("15");
    expect(totals.taxAmount).toBe("1.50");
    expect(totals.lineTotal).toBe("16.5");
  });

  it("treats blank fields as zero", () => {
    expect(computeNoteLineTotals(line({ quantity: "2", unit_price: "3", discount: "", tax_rate: "" })).lineTotal).toBe("6");
  });
});

describe("computeNoteTotals", () => {
  it("sums lines into subtotal, discount, tax and grand total", () => {
    const totals = computeNoteTotals([
      line({ quantity: "1", unit_price: "100", discount: "10", tax_rate: "10" }),
      line({ quantity: "2", unit_price: "50", discount: "0", tax_rate: "0" }),
    ]);

    expect(totals.subtotal).toBe("200");
    expect(totals.discountTotal).toBe("10");
    expect(totals.taxTotal).toBe("9");
    expect(totals.grandTotal).toBe("199");
  });

  it("returns zeros for no lines", () => {
    expect(computeNoteTotals([])).toEqual({
      subtotal: "0",
      discountTotal: "0",
      taxTotal: "0",
      grandTotal: "0",
    });
  });
});
