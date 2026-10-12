import { describe, expect, it } from "vitest";

import type { PeriodWritePayload } from "./types";
import {
  parseAccountingPeriod,
  parseAccountingPeriodList,
  parseAccountingPeriodOrThrow,
  parseClosingCheck,
  PeriodsDataError,
  validatePeriodWrite,
} from "./validators";

// Local fixture mirroring the verified `AccountingPeriodResource`.
const period = {
  id: 1,
  name: "April 2026",
  start_date: "2026-04-01",
  end_date: "2026-04-30",
  status: "OPEN",
  accepts_postings: true,
  financial_year_id: 4,
  financial_year: { id: 4, name: "2026-2027", status: "OPEN" },
  closed_by: null,
  closed_at: null,
  reopened_by: null,
  reopened_at: null,
  created_at: "2026-04-01T09:00:00+00:00",
  updated_at: "2026-04-01T09:00:00+00:00",
};

describe("parseAccountingPeriod", () => {
  it("narrows a valid row", () => {
    expect(parseAccountingPeriod(period)).toEqual(period);
  });

  it("returns null for a non-record", () => {
    expect(parseAccountingPeriod(null)).toBeNull();
    expect(parseAccountingPeriod(1)).toBeNull();
  });

  it("returns null when a required identity field is missing", () => {
    expect(parseAccountingPeriod({ ...period, id: undefined })).toBeNull();
    expect(parseAccountingPeriod({ ...period, name: 4 })).toBeNull();
    expect(parseAccountingPeriod({ ...period, status: "LOCKED" })).toBeNull();
    expect(parseAccountingPeriod({ ...period, accepts_postings: undefined })).toBeNull();
  });

  it("degrades a malformed embedded financial year to null", () => {
    expect(parseAccountingPeriod({ ...period, financial_year: "nope" })?.financial_year).toBeNull();
    expect(
      parseAccountingPeriod({ ...period, financial_year: { id: 4, name: "2026" } })?.financial_year,
    ).toBeNull();
  });

  it("tolerates an unattached period", () => {
    const parsed = parseAccountingPeriod({
      ...period,
      financial_year_id: null,
      financial_year: null,
    });

    expect(parsed?.financial_year_id).toBeNull();
    expect(parsed?.financial_year).toBeNull();
  });
});

describe("parseAccountingPeriodList", () => {
  it("narrows a flat array and infers hasMore", () => {
    const state = parseAccountingPeriodList([period], 50);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });

  it("reports hasMore for a full page", () => {
    expect(parseAccountingPeriodList([period, { ...period, id: 2 }], 2).hasMore).toBe(true);
  });

  it("skips invalid rows but keeps the valid ones", () => {
    const state = parseAccountingPeriodList([period, { name: "broken" }, { ...period, id: 3 }], 50);
    expect(state.items.map((item) => item.id)).toEqual([1, 3]);
  });

  it("errors when the payload is not a list and no row is valid", () => {
    expect(parseAccountingPeriodList("nope", 50).status).toBe("error");
  });
});

describe("parseAccountingPeriodOrThrow", () => {
  it("throws PeriodsDataError for an invalid payload", () => {
    expect(() => parseAccountingPeriodOrThrow({})).toThrow(PeriodsDataError);
  });

  it("returns the period for a valid payload", () => {
    expect(parseAccountingPeriodOrThrow(period).id).toBe(1);
  });
});

describe("parseClosingCheck", () => {
  const check = {
    period: {
      id: 1,
      name: "April 2026",
      start_date: "2026-04-01",
      end_date: "2026-04-30",
      status: "OPEN",
      financial_year_id: 4,
    },
    eligible: false,
    already_closed: false,
    summary: { PASS: 2, WARNING: 1, FAIL: 1 },
    blocking_findings: [
      {
        control_code: "UNBALANCED_JOURNAL",
        status: "FAIL",
        description: "A posted journal is unbalanced.",
        resource_type: "journal",
        resource_id: 12,
        financial_date: "2026-04-15",
        details: [],
      },
    ],
    warning_findings: [],
    findings: [],
    required_action: "Resolve the 1 blocking control finding(s) before closing this period.",
  };

  it("narrows the decision and the findings", () => {
    const parsed = parseClosingCheck(check);

    expect(parsed?.eligible).toBe(false);
    expect(parsed?.summary.FAIL).toBe(1);
    expect(parsed?.blocking_findings[0]?.control_code).toBe("UNBALANCED_JOURNAL");
  });

  it("returns null when the decision itself is missing", () => {
    expect(parseClosingCheck(null)).toBeNull();
    expect(parseClosingCheck({ ...check, period: undefined })).toBeNull();
    expect(parseClosingCheck({ ...check, eligible: undefined })).toBeNull();
    expect(parseClosingCheck({ ...check, already_closed: "no" })).toBeNull();
  });

  it("defaults a missing summary and skips unknown findings", () => {
    const parsed = parseClosingCheck({
      ...check,
      summary: undefined,
      blocking_findings: [{ status: "FAIL" }, { control_code: "X", status: "NOPE" }],
    });

    expect(parsed?.summary).toEqual({ PASS: 0, WARNING: 0, FAIL: 0 });
    expect(parsed?.blocking_findings).toEqual([]);
  });
});

describe("validatePeriodWrite", () => {
  const base: PeriodWritePayload = {
    name: "April 2026",
    start_date: "2026-04-01",
    end_date: "2026-04-30",
    financial_year_id: null,
  };

  it("accepts a valid payload", () => {
    expect(validatePeriodWrite(base)).toEqual({});
  });

  it("requires a name", () => {
    expect(validatePeriodWrite({ ...base, name: " " }).name).toBeDefined();
  });

  it("rejects malformed and reversed dates", () => {
    expect(validatePeriodWrite({ ...base, start_date: "2026/04/01" }).start_date).toBeDefined();
    expect(validatePeriodWrite({ ...base, end_date: "" }).end_date).toBeDefined();
    expect(validatePeriodWrite({ ...base, end_date: "2026-03-01" }).end_date).toBeDefined();
  });

  it("rejects an invalid financial year id", () => {
    expect(validatePeriodWrite({ ...base, financial_year_id: 0 }).financial_year_id).toBeDefined();
    expect(validatePeriodWrite({ ...base, financial_year_id: -3 }).financial_year_id).toBeDefined();
  });

  it("accepts an update payload without a financial year", () => {
    expect(
      validatePeriodWrite({ name: "April", start_date: "2026-04-01", end_date: "2026-04-30" }),
    ).toEqual({});
  });
});
