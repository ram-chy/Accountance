import { describe, expect, it } from "vitest";

import {
  CurrenciesDataError,
  parseCurrency,
  parseCurrencyDetail,
  parseCurrencyList,
  parseCurrencyOrThrow,
  validateCurrencyCreate,
  validateCurrencyUpdate,
} from "./validators";

const validCurrency = {
  id: 1,
  code: "USD",
  name: "US Dollar",
  symbol: "$",
  decimal_precision: 2,
  is_active: true,
  created_at: "2026-04-01T00:00:00Z",
  updated_at: null,
};

describe("parseCurrency", () => {
  it("narrows a complete row", () => {
    const currency = parseCurrency(validCurrency);

    expect(currency).not.toBeNull();
    expect(currency?.code).toBe("USD");
    expect(currency?.decimal_precision).toBe(2);
  });

  it("tolerates a null symbol", () => {
    const currency = parseCurrency({ ...validCurrency, symbol: null });
    expect(currency?.symbol).toBeNull();
  });

  it("drops a row missing a required identifier", () => {
    expect(parseCurrency({ ...validCurrency, id: undefined })).toBeNull();
  });

  it("drops a row whose active flag is not a boolean", () => {
    expect(parseCurrency({ ...validCurrency, is_active: "yes" })).toBeNull();
  });

  it("drops a row missing its decimal precision", () => {
    expect(parseCurrency({ ...validCurrency, decimal_precision: undefined })).toBeNull();
  });
});

describe("parseCurrencyList", () => {
  it("keeps valid rows and skips invalid ones", () => {
    const state = parseCurrencyList([validCurrency, { id: 2 }, { ...validCurrency, id: 3, code: "EUR" }]);

    expect(state.status).toBe("ready");
    expect(state.items.map((item) => item.id)).toEqual([1, 3]);
  });

  it("errors when no row is valid", () => {
    expect(parseCurrencyList([{ id: 2 }, null]).status).toBe("error");
  });

  it("errors when the payload is not a list", () => {
    expect(parseCurrencyList("nope").status).toBe("error");
  });
});

describe("parseCurrencyOrThrow", () => {
  it("throws a CurrenciesDataError for a malformed payload", () => {
    expect(() => parseCurrencyOrThrow({})).toThrow(CurrenciesDataError);
  });
});

describe("parseCurrencyDetail", () => {
  it("narrows a detail response", () => {
    expect(parseCurrencyDetail(validCurrency).currency?.code).toBe("USD");
  });

  it("errors on a malformed detail response", () => {
    expect(parseCurrencyDetail({}).status).toBe("error");
  });
});

describe("validateCurrencyCreate", () => {
  it("requires a code and a name", () => {
    const errors = validateCurrencyCreate({
      code: "",
      name: "",
      symbol: null,
      decimal_precision: 2,
    });

    expect(errors.code).toBeDefined();
    expect(errors.name).toBeDefined();
  });

  it("rejects a code that is not three letters", () => {
    const errors = validateCurrencyCreate({
      code: "US",
      name: "US Dollar",
      symbol: null,
      decimal_precision: 2,
    });

    expect(errors.code).toBeDefined();
  });

  it("accepts a valid payload", () => {
    expect(
      validateCurrencyCreate({ code: "USD", name: "US Dollar", symbol: "$", decimal_precision: 2 }),
    ).toEqual({});
  });

  it("rejects a precision outside the supported range", () => {
    const errors = validateCurrencyCreate({
      code: "USD",
      name: "US Dollar",
      symbol: null,
      decimal_precision: 9,
    });

    expect(errors.decimal_precision).toBeDefined();
  });
});

describe("validateCurrencyUpdate", () => {
  it("does not require fields (PUT is partial)", () => {
    expect(validateCurrencyUpdate({})).toEqual({});
  });

  it("still rejects an invalid code when present", () => {
    expect(validateCurrencyUpdate({ code: "US" }).code).toBeDefined();
  });
});
