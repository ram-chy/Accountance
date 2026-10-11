/**
 * Date and timestamp formatting.
 *
 * Two distinct backend shapes are handled explicitly:
 * - business dates are date-only strings (`YYYY-MM-DD`)
 * - audit timestamps are ISO-8601 UTC instants
 *
 * Date-only values are parsed manually and formatted in UTC so a calendar date
 * never shifts by a day for users in negative/positive offsets. Timestamps
 * default to UTC (the application time zone); a company time zone can be passed
 * when Phase 2 wires company context through.
 */

const DATE_ONLY_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

export function isDateOnly(value: unknown): value is string {
  return typeof value === "string" && DATE_ONLY_PATTERN.test(value.trim());
}

export type FormatDateOptions = {
  locale?: string;
  fallback?: string;
};

/** Format a `YYYY-MM-DD` business date; returns `fallback` if invalid. */
export function formatDateOnly(
  value: string | null | undefined,
  options: FormatDateOptions = {},
): string {
  const { locale = "en-US", fallback = "—" } = options;

  if (!isDateOnly(value)) {
    return fallback;
  }

  const [year, month, day] = value.trim().split("-").map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));

  // Reject impossible calendar dates (for example 2026-02-31).
  if (
    date.getUTCFullYear() !== year ||
    date.getUTCMonth() !== month - 1 ||
    date.getUTCDate() !== day
  ) {
    return fallback;
  }

  return new Intl.DateTimeFormat(locale, {
    year: "numeric",
    month: "short",
    day: "2-digit",
    timeZone: "UTC",
  }).format(date);
}

export type FormatTimestampOptions = {
  locale?: string;
  /** IANA time zone. Defaults to UTC. */
  timeZone?: string;
  dateStyle?: "short" | "medium" | "long" | "full";
  timeStyle?: "short" | "medium" | "long" | "full";
  fallback?: string;
};

/** Format an ISO-8601 timestamp; returns `fallback` if unparseable. */
export function formatTimestamp(
  value: string | null | undefined,
  options: FormatTimestampOptions = {},
): string {
  const {
    locale = "en-US",
    timeZone = "UTC",
    dateStyle = "medium",
    timeStyle = "short",
    fallback = "—",
  } = options;

  if (value === null || value === undefined || value === "") {
    return fallback;
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return fallback;
  }

  return new Intl.DateTimeFormat(locale, {
    dateStyle,
    timeStyle,
    timeZone,
  }).format(date);
}
