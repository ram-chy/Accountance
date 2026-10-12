import { describe, expect, it } from "vitest";

import type { FinancialYearWritePayload } from "./types";
import {
  FinancialYearsDataError,
  parseFinancialYear,
  parseFinancialYearList,
  parseFinancialYearOrThrow,
  parseGeneratePeriodsResult,
  validateFinancialYearWrite,
} from "./validators";

// Local fixture mirroring the verified `FinancialYearResource`.
const year = {
  id: 1,
  name: "2026-2027",
  start_date: "2026-04-01",
  end_date: "2027-03-31",
  status: "OPEN",
  accepts_postings: true,
  periods_count: 12,
  open_periods_count: 3,
  can_close: false,
  created_by: 7,
  closed_by: null,
  closed_at: null,
  created_at: "2026-04-01T09:00:00+00:00",
  updated_at: "2026-04-01T09:00:00+00:00",
};

describe("parseFinancialYear", () => {
  it("narrows a valid row", () => {
    expect(parseFinancialYear(year)).toEqual(year);
  });

  it("returns null for a non-record", () => {
    expect(parseFinancialYear(null)).toBeNull();
    expect(parseFinancialYear("2026")).toBeNull();
  });

  it("returns null when a required identity field is missing", () => {
    expect(parseFinancialYear({ ...year, id: undefined })).toBeNull();
    expect(parseFinancialYear({ ...year, name: 2026 })).toBeNull();
    expect(parseFinancialYear({ ...year, status: "PENDING" })).toBeNull();
    expect(parseFinancialYear({ ...year, start_date: undefined })).toBeNull();
  });

  it("returns null when a derived flag or count is missing", () => {
    expect(parseFinancialYear({ ...year, accepts_postings: undefined })).toBeNull();
    expect(parseFinancialYear({ ...year, can_close: "yes" })).toBeNull();
    expect(parseFinancialYear({ ...year, periods_count: undefined })).toBeNull();
    expect(parseFinancialYear({ ...year, open_periods_count: -1 })).toBeNull();
  });
});

describe("parseFinancialYearList", () => {
  it("narrows a flat array and infers hasMore", () => {
    const state = parseFinancialYearList([year], 50);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
    expect(state.hasMore).toBe(false);
  });

  it("reports hasMore for a full page", () => {
    expect(parseFinancialYearList([year, { ...year, id: 2 }], 2).hasMore).toBe(true);
  });

  it("skips invalid rows but keeps the valid ones", () => {
    const state = parseFinancialYearList(
      [year, { name: "broken" }, { ...year, id: 3 }],
      50,
    );

    expect(state.items.map((item) => item.id)).toEqual([1, 3]);
  });

  it("errors when the payload is not a list and no row is valid", () => {
    expect(parseFinancialYearList("nope", 50).status).toBe("error");
    expect(parseFinancialYearList([{ name: "broken" }], 50).status).toBe("error");
  });
});

describe("parseFinancialYearOrThrow", () => {
  it("returns the year for a valid payload", () => {
    expect(parseFinancialYearOrThrow(year).id).toBe(1);
  });

  it("throws FinancialYearsDataError for an invalid payload", () => {
    expect(() => parseFinancialYearOrThrow({})).toThrow(FinancialYearsDataError);
  });
});

describe("parseGeneratePeriodsResult", () => {
  it("narrows the generate response", () => {
    const result = parseGeneratePeriodsResult({
      financial_year: year,
      periods_count: 12,
    });

    expect(result?.periods_count).toBe(12);
    expect(result?.financial_year?.id).toBe(1);
  });

  it("tolerates a missing year but requires the count", () => {
    expect(parseGeneratePeriodsResult({ periods_count: 0 })?.periods_count).toBe(0);
    expect(parseGeneratePeriodsResult({ financial_year: year })).toBeNull();
    expect(parseGeneratePeriodsResult(null)).toBeNull();
  });
});

describe("validateFinancialYearWrite", () => {
  const base: FinancialYearWritePayload = {
    name: "2026-2027",
    start_date: "2026-04-01",
    end_date: "2027-03-31",
  };

  it("accepts a valid payload", () => {
    expect(validateFinancialYearWrite(base)).toEqual({});
  });

  it("requires a name", () => {
    expect(validateFinancialYearWrite({ ...base, name: "  " }).name).toBeDefined();
  });

  it("rejects an over-long name", () => {
    expect(validateFinancialYearWrite({ ...base, name: "x".repeat(256) }).name).toBeDefined();
  });

  it("rejects malformed dates", () => {
    expect(validateFinancialYearWrite({ ...base, start_date: "01/04/2026" }).start_date).toBeDefined();
    expect(validateFinancialYearWrite({ ...base, end_date: "" }).end_date).toBeDefined();
  });

  it("rejects an end date before the start date", () => {
    expect(
      validateFinancialYearWrite({ ...base, end_date: "2026-03-31" }).end_date,
    ).toBeDefined();
  });
});
