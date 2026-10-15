import { describe, expect, it } from "vitest";

import {
  ExchangeRatesDataError,
  parseExchangeRate,
  parseExchangeRateDetail,
  parseExchangeRateList,
  parseExchangeRateOrThrow,
  validateExchangeRateCreate,
  validateExchangeRateUpdate,
} from "./validators";

const validRate = {
  id: 1,
  from_currency_id: 1,
  to_currency_id: 2,
  from_currency: "USD",
  to_currency: "INR",
  pair: "USD/INR",
  effective_date: "2026-04-01",
  rate: "83.5000000000",
  is_active: true,
  source: "ECB",
  created_by: 1,
  updated_by: null,
  created_at: "2026-04-01T00:00:00Z",
  updated_at: null,
};

describe("parseExchangeRate", () => {
  it("narrows a complete row and keeps the rate as a string", () => {
    const rate = parseExchangeRate(validRate);

    expect(rate).not.toBeNull();
    expect(rate?.rate).toBe("83.5000000000");
    expect(rate?.pair).toBe("USD/INR");
  });

  it("tolerates missing currency codes when the relation was not loaded", () => {
    const rate = parseExchangeRate({
      ...validRate,
      from_currency: undefined,
      to_currency: undefined,
      pair: undefined,
    });

    expect(rate?.from_currency).toBeNull();
    expect(rate?.pair).toBeNull();
  });

  it("drops a row missing its rate", () => {
    expect(parseExchangeRate({ ...validRate, rate: undefined })).toBeNull();
  });

  it("drops a row missing its effective date", () => {
    expect(parseExchangeRate({ ...validRate, effective_date: undefined })).toBeNull();
  });

  it("drops a row whose active flag is not a boolean", () => {
    expect(parseExchangeRate({ ...validRate, is_active: "yes" })).toBeNull();
  });
});

describe("parseExchangeRateList", () => {
  it("keeps valid rows and skips invalid ones", () => {
    const state = parseExchangeRateList([validRate, { id: 2 }, { ...validRate, id: 3 }]);

    expect(state.status).toBe("ready");
    expect(state.items.map((item) => item.id)).toEqual([1, 3]);
  });

  it("errors when no row is valid", () => {
    expect(parseExchangeRateList([{ id: 2 }]).status).toBe("error");
  });

  it("errors when the payload is not a list", () => {
    expect(parseExchangeRateList("nope").status).toBe("error");
  });
});

describe("parseExchangeRateOrThrow", () => {
  it("throws an ExchangeRatesDataError for a malformed payload", () => {
    expect(() => parseExchangeRateOrThrow({})).toThrow(ExchangeRatesDataError);
  });
});

describe("parseExchangeRateDetail", () => {
  it("narrows a detail response", () => {
    expect(parseExchangeRateDetail(validRate).rate?.id).toBe(1);
  });

  it("errors on a malformed detail response", () => {
    expect(parseExchangeRateDetail({}).status).toBe("error");
  });
});

describe("validateExchangeRateCreate", () => {
  const base = {
    from_currency_id: 1,
    to_currency_id: 2,
    rate: "83.5",
    effective_date: "2026-04-01",
  };

  it("accepts a valid payload", () => {
    expect(validateExchangeRateCreate(base)).toEqual({});
  });

  it("requires a from and a to currency", () => {
    const errors = validateExchangeRateCreate({
      ...base,
      from_currency_id: 0,
      to_currency_id: 0,
    });

    expect(errors.from_currency_id).toBeDefined();
    expect(errors.to_currency_id).toBeDefined();
  });

  it("rejects a rate that is not greater than zero", () => {
    expect(validateExchangeRateCreate({ ...base, rate: "0" }).rate).toBeDefined();
    expect(validateExchangeRateCreate({ ...base, rate: "0.0000" }).rate).toBeDefined();
  });

  it("rejects a non-decimal rate", () => {
    expect(validateExchangeRateCreate({ ...base, rate: "abc" }).rate).toBeDefined();
    expect(validateExchangeRateCreate({ ...base, rate: "-1" }).rate).toBeDefined();
  });

  it("accepts a small fractional rate", () => {
    expect(validateExchangeRateCreate({ ...base, rate: "0.000062" })).toEqual({});
  });

  it("rejects a malformed effective date", () => {
    expect(validateExchangeRateCreate({ ...base, effective_date: "01/04/2026" }).effective_date).toBeDefined();
  });
});

describe("validateExchangeRateUpdate", () => {
  it("does not require fields (PUT is partial)", () => {
    expect(validateExchangeRateUpdate({})).toEqual({});
  });

  it("still rejects an invalid rate when present", () => {
    expect(validateExchangeRateUpdate({ rate: "0" }).rate).toBeDefined();
  });

  it("rejects a malformed date when present", () => {
    expect(validateExchangeRateUpdate({ effective_date: "nope" }).effective_date).toBeDefined();
  });
});
