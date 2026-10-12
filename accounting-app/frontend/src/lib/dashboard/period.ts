/**
 * Reporting-period presets and month bucketing.
 *
 * Pure date arithmetic over plain `YYYY-MM-DD` strings so it can be unit-tested
 * without a clock or a time zone. The fiscal-year presets honour the company's
 * configured start month (`CompanySettingResource.default_fiscal_year_start_month`);
 * a missing or out-of-range value falls back to a calendar year.
 */

export type PeriodPreset =
  | "this-month"
  | "last-month"
  | "this-quarter"
  | "this-financial-year"
  | "previous-financial-year"
  | "last-12-months"
  | "custom";

export type DashboardPeriod = {
  preset: PeriodPreset;
  from: string | null;
  to: string | null;
};

export type DateRange = { from: string; to: string };

/** Maximum months plotted in the trend chart (one aggregated P&L call each). */
export const MAX_TREND_MONTHS = 12;

export const PERIOD_PRESETS: readonly { value: PeriodPreset; label: string }[] = [
  { value: "this-month", label: "This month" },
  { value: "last-month", label: "Last month" },
  { value: "this-quarter", label: "This quarter" },
  { value: "this-financial-year", label: "This financial year" },
  { value: "previous-financial-year", label: "Previous financial year" },
  { value: "last-12-months", label: "Last 12 months" },
  { value: "custom", label: "Custom range" },
];

const MONTH_NAMES = [
  "Jan",
  "Feb",
  "Mar",
  "Apr",
  "May",
  "Jun",
  "Jul",
  "Aug",
  "Sep",
  "Oct",
  "Nov",
  "Dec",
] as const;

function pad(value: number): string {
  return String(value).padStart(2, "0");
}

function toDateString(date: Date): string {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** Parse a `YYYY-MM-DD` string into a local Date at midnight, or null. */
export function parseDateOnly(value: string | null | undefined): Date | null {
  if (typeof value !== "string") {
    return null;
  }

  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());

  if (!match) {
    return null;
  }

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const date = new Date(year, month - 1, day);

  if (
    date.getFullYear() !== year ||
    date.getMonth() !== month - 1 ||
    date.getDate() !== day
  ) {
    return null;
  }

  return date;
}

function clampFiscalStart(month: number | null | undefined): number {
  if (typeof month !== "number" || !Number.isInteger(month) || month < 1 || month > 12) {
    return 1;
  }

  return month;
}

/**
 * First and last day of the fiscal year containing `asOf`.
 *
 * The fiscal year starts on the first day of `fiscalStartMonth`. When the
 * current month is before that, the year began in the previous calendar year.
 */
export function financialYearRange(
  asOf: Date,
  fiscalStartMonth: number | null | undefined,
): DateRange {
  const start = clampFiscalStart(fiscalStartMonth);
  const year = asOf.getFullYear();
  const startYear = asOf.getMonth() + 1 >= start ? year : year - 1;

  const from = new Date(startYear, start - 1, 1);
  const to = new Date(startYear + 1, start - 1, 0);

  return { from: toDateString(from), to: toDateString(to) };
}

/** Resolve a preset to a concrete inclusive window. `custom` yields nulls. */
export function resolvePeriod(
  preset: PeriodPreset,
  now: Date,
  fiscalStartMonth: number | null | undefined,
): DateRange | { from: null; to: null } {
  const year = now.getFullYear();
  const month = now.getMonth();

  switch (preset) {
    case "this-month":
      return {
        from: toDateString(new Date(year, month, 1)),
        to: toDateString(new Date(year, month + 1, 0)),
      };
    case "last-month": {
      const lastYear = month === 0 ? year - 1 : year;
      const lastMonth = month === 0 ? 11 : month - 1;
      return {
        from: toDateString(new Date(lastYear, lastMonth, 1)),
        to: toDateString(new Date(lastYear, lastMonth + 1, 0)),
      };
    }
    case "this-quarter": {
      const quarterStart = Math.floor(month / 3) * 3;
      return {
        from: toDateString(new Date(year, quarterStart, 1)),
        to: toDateString(new Date(year, quarterStart + 3, 0)),
      };
    }
    case "this-financial-year":
      return financialYearRange(now, fiscalStartMonth);
    case "previous-financial-year": {
      const previous = new Date(year - 1, 11, 31);
      return financialYearRange(previous, fiscalStartMonth);
    }
    case "last-12-months":
      return {
        from: toDateString(new Date(year, month - 11, 1)),
        to: toDateString(new Date(year, month + 1, 0)),
      };
    case "custom":
    default:
      return { from: null, to: null };
  }
}

/** The default dashboard window: the current financial year. */
export function defaultPeriod(
  now: Date,
  fiscalStartMonth: number | null | undefined,
): DashboardPeriod {
  const range = resolvePeriod("this-financial-year", now, fiscalStartMonth);
  return { preset: "this-financial-year", from: range.from, to: range.to };
}

export type MonthBucket = {
  key: string;
  label: string;
  from: string;
  to: string;
};

/**
 * Split an inclusive range into calendar-month buckets.
 *
 * Each bucket's `from`/`to` are the first/last day of that month, clamped to the
 * range ends so a partial first or last month still reconciles with the P&L
 * window it was derived from.
 */
export function monthBuckets(from: string | null, to: string | null): MonthBucket[] {
  const start = parseDateOnly(from);
  const end = parseDateOnly(to);

  if (!start || !end || start > end) {
    return [];
  }

  const buckets: MonthBucket[] = [];
  let cursor = new Date(start.getFullYear(), start.getMonth(), 1);
  const lastMonth = new Date(end.getFullYear(), end.getMonth(), 1);
  let guard = 0;

  while (cursor <= lastMonth && guard < 600) {
    guard += 1;

    const year = cursor.getFullYear();
    const month = cursor.getMonth();
    const monthStart = new Date(year, month, 1);
    const monthEnd = new Date(year, month + 1, 0);

    buckets.push({
      key: `${year}-${pad(month + 1)}`,
      label: `${MONTH_NAMES[month]} ${year}`,
      from: toDateString(monthStart < start ? start : monthStart),
      to: toDateString(monthEnd > end ? end : monthEnd),
    });

    cursor = new Date(year, month + 1, 1);
  }

  return buckets;
}

/** A human label for the resolved window, e.g. "1 Apr 2026 – 31 Mar 2027". */
export function formatRangeLabel(from: string | null, to: string | null): string {
  const start = parseDateOnly(from);
  const end = parseDateOnly(to);

  if (!start || !end) {
    return "Select a date range";
  }

  const format = (date: Date) =>
    `${date.getDate()} ${MONTH_NAMES[date.getMonth()]} ${date.getFullYear()}`;

  return `${format(start)} – ${format(end)}`;
}
