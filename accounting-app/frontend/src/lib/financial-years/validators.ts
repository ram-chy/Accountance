/**
 * Runtime validation for financial-year responses and inputs.
 *
 * API JSON is untrusted input. The parsers below narrow a raw payload to the
 * exact fields the UI renders. A row missing a required identifier, name, date
 * or a known status — or missing the lifecycle booleans the UI renders as status
 * and eligibility — is treated as invalid and skipped, the same "filter the
 * invalid row, never fabricate" strategy the account and journal validators use.
 * Write payloads are checked against the backend's own rules for immediate
 * feedback; the backend remains the validator of record.
 */

import { normalizeList } from "@/lib/api/pagination";

import {
  FINANCIAL_YEAR_STATUS_VALUES,
  type FinancialYear,
  type FinancialYearListState,
  type FinancialYearStatusValue,
  type FinancialYearWritePayload,
  type GeneratePeriodsResult,
} from "./types";

export class FinancialYearsDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "FinancialYearsDataError";
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function asString(value: unknown): string | undefined {
  return typeof value === "string" ? value : undefined;
}

function asNullableString(value: unknown): string | null {
  return typeof value === "string" ? value : null;
}

function asNumber(value: unknown): number | undefined {
  return typeof value === "number" && Number.isFinite(value) ? value : undefined;
}

function asCount(value: unknown): number | undefined {
  return typeof value === "number" && Number.isFinite(value) && value >= 0
    ? value
    : undefined;
}

function asNullableNumber(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

function asBoolean(value: unknown): boolean | undefined {
  return typeof value === "boolean" ? value : undefined;
}

function asStatus(value: unknown): FinancialYearStatusValue | undefined {
  return FINANCIAL_YEAR_STATUS_VALUES.includes(value as FinancialYearStatusValue)
    ? (value as FinancialYearStatusValue)
    : undefined;
}

/**
 * Narrow one financial-year row, or return null when it is not safe to render.
 *
 * Required: id, name, both dates, a known status, and the four derived
 * booleans/counts the UI turns into status and action affordances. Optional
 * audit fields degrade to null.
 */
export function parseFinancialYear(value: unknown): FinancialYear | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = asNumber(value.id);
  const name = asString(value.name);
  const start_date = asString(value.start_date);
  const end_date = asString(value.end_date);
  const status = asStatus(value.status);
  const accepts_postings = asBoolean(value.accepts_postings);
  const periods_count = asCount(value.periods_count);
  const open_periods_count = asCount(value.open_periods_count);
  const can_close = asBoolean(value.can_close);

  if (
    id === undefined ||
    name === undefined ||
    start_date === undefined ||
    end_date === undefined ||
    status === undefined ||
    accepts_postings === undefined ||
    periods_count === undefined ||
    open_periods_count === undefined ||
    can_close === undefined
  ) {
    return null;
  }

  return {
    id,
    name,
    start_date,
    end_date,
    status,
    accepts_postings,
    periods_count,
    open_periods_count,
    can_close,
    created_by: asNullableNumber(value.created_by),
    closed_by: asNullableNumber(value.closed_by),
    closed_at: asNullableString(value.closed_at),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toErrorState(message: string): FinancialYearListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

/**
 * Narrow the flat (or future paginator-shaped) list payload into a validated
 * `FinancialYearListState`. Invalid rows are skipped, never fabricated; the
 * `perPage` used to infer `hasMore` comes from the caller.
 */
export function parseFinancialYearList(
  raw: unknown,
  perPage: number,
): FinancialYearListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toErrorState("The financial years response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: FinancialYear[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const year = parseFinancialYear(entry);

    if (year === null) {
      skipped += 1;
      continue;
    }

    items.push(year);
  }

  if (skipped > 0 && items.length === 0) {
    return toErrorState(
      "The financial years response contained no valid financial-year rows.",
    );
  }

  return { status: "ready", items, perPage, hasMore: list.hasMore };
}

/** Narrow a single write-response payload, or throw when malformed. */
export function parseFinancialYearOrThrow(
  raw: unknown,
  label = "financial year",
): FinancialYear {
  const year = parseFinancialYear(raw);

  if (year === null) {
    throw new FinancialYearsDataError(`The ${label} response was invalid.`);
  }

  return year;
}

/**
 * Narrow `POST .../periods/generate`. The endpoint reports how many periods now
 * exist after the (idempotent) call; the year payload is best-effort.
 */
export function parseGeneratePeriodsResult(raw: unknown): GeneratePeriodsResult | null {
  if (!isRecord(raw)) {
    return null;
  }

  const count = asCount(raw.periods_count);

  if (count === undefined) {
    return null;
  }

  return {
    financial_year: parseFinancialYear(raw.financial_year),
    periods_count: count,
  };
}

export type FinancialYearValidationErrors = Record<string, string>;

/** `date_format:Y-m-d`. */
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Client-side validation mirroring `StoreFinancialYearRequest` /
 * `UpdateFinancialYearRequest`.
 *
 * Feedback only — the backend re-validates every write. Rules are kept
 * identical to the verified Laravel rules so a client pass means the request is
 * very likely to pass server-side too.
 */
export function validateFinancialYearWrite(
  payload: FinancialYearWritePayload,
): FinancialYearValidationErrors {
  const errors: FinancialYearValidationErrors = {};

  if (!payload.name.trim()) {
    errors.name = "The financial year name is required.";
  } else if (payload.name.length > 255) {
    errors.name = "The financial year name may not be longer than 255 characters.";
  }

  if (!DATE_PATTERN.test(payload.start_date.trim())) {
    errors.start_date = "Enter the start date as YYYY-MM-DD.";
  }

  if (!DATE_PATTERN.test(payload.end_date.trim())) {
    errors.end_date = "Enter the end date as YYYY-MM-DD.";
  }

  if (
    !errors.start_date &&
    !errors.end_date &&
    payload.end_date.trim() < payload.start_date.trim()
  ) {
    errors.end_date = "The financial year end date must not be before its start date.";
  }

  return errors;
}
