import { describe, expect, it } from "vitest";

import {
  defaultPeriod,
  financialYearRange,
  formatRangeLabel,
  monthBuckets,
  parseDateOnly,
  resolvePeriod,
} from "./period";

describe("parseDateOnly", () => {
  it("parses valid date-only strings at local midnight", () => {
    const parsed = parseDateOnly("2026-04-01");
    expect(parsed).not.toBeNull();
    expect(parsed?.getFullYear()).toBe(2026);
    expect(parsed?.getMonth()).toBe(3);
    expect(parsed?.getDate()).toBe(1);
  });

  it("rejects impossible dates and non-date input", () => {
    expect(parseDateOnly("2026-02-31")).toBeNull();
    expect(parseDateOnly("01-04-2026")).toBeNull();
    expect(parseDateOnly("nonsense")).toBeNull();
    expect(parseDateOnly(null)).toBeNull();
  });
});

describe("financialYearRange", () => {
  it("starts the year in the configured month", () => {
    const now = new Date(2026, 3, 15); // 15 Apr 2026
    expect(financialYearRange(now, 4)).toEqual({
      from: "2026-04-01",
      to: "2027-03-31",
    });
  });

  it("uses the previous calendar year before the fiscal start", () => {
    const now = new Date(2026, 1, 10); // 10 Feb 2026
    expect(financialYearRange(now, 4)).toEqual({
      from: "2025-04-01",
      to: "2026-03-31",
    });
  });

  it("falls back to a calendar year for an invalid start month", () => {
    const now = new Date(2026, 5, 10);
    expect(financialYearRange(now, 0)).toEqual({
      from: "2026-01-01",
      to: "2026-12-31",
    });
    expect(financialYearRange(now, null)).toEqual({
      from: "2026-01-01",
      to: "2026-12-31",
    });
  });
});

describe("resolvePeriod", () => {
  const now = new Date(2026, 4, 20); // 20 May 2026

  it("resolves month, quarter and rolling presets", () => {
    expect(resolvePeriod("this-month", now, 1)).toEqual({
      from: "2026-05-01",
      to: "2026-05-31",
    });
    expect(resolvePeriod("last-month", now, 1)).toEqual({
      from: "2026-04-01",
      to: "2026-04-30",
    });
    expect(resolvePeriod("this-quarter", now, 1)).toEqual({
      from: "2026-04-01",
      to: "2026-06-30",
    });
    expect(resolvePeriod("last-12-months", now, 1)).toEqual({
      from: "2025-06-01",
      to: "2026-05-31",
    });
  });

  it("wraps last-month across the year boundary", () => {
    const january = new Date(2026, 0, 5);
    expect(resolvePeriod("last-month", january, 1)).toEqual({
      from: "2025-12-01",
      to: "2025-12-31",
    });
  });

  it("returns nulls for a custom range", () => {
    expect(resolvePeriod("custom", now, 1)).toEqual({ from: null, to: null });
  });
});

describe("defaultPeriod", () => {
  it("defaults to the current financial year", () => {
    const now = new Date(2026, 3, 15);
    expect(defaultPeriod(now, 4)).toEqual({
      preset: "this-financial-year",
      from: "2026-04-01",
      to: "2027-03-31",
    });
  });
});

describe("monthBuckets", () => {
  it("splits a range into months and clamps partial month ends", () => {
    const buckets = monthBuckets("2026-04-15", "2026-06-10");

    expect(buckets.map((bucket) => bucket.key)).toEqual([
      "2026-04",
      "2026-05",
      "2026-06",
    ]);
    expect(buckets[0]).toEqual({
      key: "2026-04",
      label: "Apr 2026",
      from: "2026-04-15",
      to: "2026-04-30",
    });
    expect(buckets[1]).toEqual({
      key: "2026-05",
      label: "May 2026",
      from: "2026-05-01",
      to: "2026-05-31",
    });
    expect(buckets[2]).toEqual({
      key: "2026-06",
      label: "Jun 2026",
      from: "2026-06-01",
      to: "2026-06-10",
    });
  });

  it("returns no buckets for an invalid or reversed range", () => {
    expect(monthBuckets("2026-06-01", "2026-04-01")).toEqual([]);
    expect(monthBuckets(null, null)).toEqual([]);
  });
});

describe("formatRangeLabel", () => {
  it("labels a resolved range", () => {
    expect(formatRangeLabel("2026-04-01", "2027-03-31")).toBe(
      "1 Apr 2026 – 31 Mar 2027",
    );
  });

  it("prompts when the range is incomplete", () => {
    expect(formatRangeLabel(null, null)).toBe("Select a date range");
  });
});
