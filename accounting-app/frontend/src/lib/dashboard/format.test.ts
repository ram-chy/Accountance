import { describe, expect, it } from "vitest";

import {
  formatCompactNumber,
  formatMoneyValue,
  hasCurrency,
  sumDecimalStrings,
  toDisplayNumber,
} from "./format";
import type { CurrencyInfo } from "./types";

const USD: CurrencyInfo = {
  code: "USD",
  name: "US Dollar",
  symbol: "$",
  decimals: 2,
};

describe("sumDecimalStrings", () => {
  it("adds decimal strings without floating-point error", () => {
    expect(sumDecimalStrings(["0.10", "0.20"])).toBe("0.3000");
    expect(sumDecimalStrings(["1", "2.5"])).toBe("3.5000");
  });

  it("handles negatives and ignores invalid entries", () => {
    expect(sumDecimalStrings(["-1.0000", "2.0000"])).toBe("1.0000");
    expect(sumDecimalStrings(["abc", null, undefined, "5"])).toBe("5.0000");
  });

  it("preserves values beyond the safe integer range", () => {
    expect(sumDecimalStrings(["9007199254740993.0000", "1.0000"])).toBe(
      "9007199254740994.0000",
    );
  });

  it("returns a canonical zero for an empty list", () => {
    expect(sumDecimalStrings([])).toBe("0.0000");
  });
});

describe("hasCurrency", () => {
  it("requires a code or a symbol", () => {
    expect(hasCurrency(USD)).toBe(true);
    expect(hasCurrency({ code: null, name: "x", symbol: null, decimals: null })).toBe(
      false,
    );
    expect(hasCurrency(null)).toBe(false);
  });
});

describe("formatMoneyValue", () => {
  it("formats using the currency symbol", () => {
    expect(formatMoneyValue("1234.5600", USD)).toBe("$ 1,234.56");
  });

  it("falls back to the currency code and then to a plain amount", () => {
    expect(formatMoneyValue("100", { ...USD, symbol: null })).toBe("100.00 USD");
    expect(formatMoneyValue("100", null)).toBe("100.00");
  });

  it("shows a dash for non-decimal input", () => {
    expect(formatMoneyValue("not-a-number", USD)).toBe("—");
    expect(formatMoneyValue(null, USD)).toBe("—");
  });
});

describe("formatCompactNumber", () => {
  it("compacts large axis values", () => {
    expect(formatCompactNumber(1200)).toBe("1.2K");
    expect(formatCompactNumber(0)).toBe("0");
  });
});

describe("toDisplayNumber", () => {
  it("parses decimal strings for display and defaults to zero", () => {
    expect(toDisplayNumber("12.50")).toBe(12.5);
    expect(toDisplayNumber(null)).toBe(0);
    expect(toDisplayNumber("bad")).toBe(0);
  });
});
