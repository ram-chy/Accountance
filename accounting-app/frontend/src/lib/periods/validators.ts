/**
 * Runtime validation for accounting-period responses and inputs.
 *
 * API JSON is untrusted input. The parsers below narrow a raw payload to the
 * exact fields the UI renders. A period missing a required identifier, name,
 * date or a known status — or missing the derived posting flag the UI renders as
 * status — is treated as invalid and skipped, the same "filter the invalid row,
 * never fabricate" strategy the account and journal validators use. The
 * closing-check parser is deliberately lenient about the report body so a new or
 * unknown finding never hides the close/refuse decision itself.
 */

import { normalizeList } from "@/lib/api/pagination";
import type { FinancialYearStatusValue } from "@/lib/financial-years/types";

import {
  CONTROL_SEVERITY_VALUES,
  PERIOD_STATUS_VALUES,
  type AccountingPeriod,
  type ClosingCheck,
  type ClosingCheckPeriod,
  type ClosingCheckSummary,
  type ControlFinding,
  type ControlSeverityValue,
  type PeriodFinancialYear,
  type PeriodListState,
  type PeriodStatusValue,
  type PeriodUpdatePayload,
  type PeriodWritePayload,
} from "./types";

export class PeriodsDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "PeriodsDataError";
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

function asNullableNumber(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

function asCount(value: unknown): number {
  return typeof value === "number" && Number.isFinite(value) && value >= 0 ? value : 0;
}

function asBoolean(value: unknown): boolean | undefined {
  return typeof value === "boolean" ? value : undefined;
}

function asPeriodStatus(value: unknown): PeriodStatusValue | undefined {
  return PERIOD_STATUS_VALUES.includes(value as PeriodStatusValue)
    ? (value as PeriodStatusValue)
    : undefined;
}

function asSeverity(value: unknown): ControlSeverityValue | undefined {
  return CONTROL_SEVERITY_VALUES.includes(value as ControlSeverityValue)
    ? (value as ControlSeverityValue)
    : undefined;
}

function parseFinancialYearRef(value: unknown): PeriodFinancialYear | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = asNumber(value.id);
  const name = asString(value.name);
  const status = asPeriodStatus(value.status);

  if (id === undefined || name === undefined || status === undefined) {
    return null;
  }

  return { id, name, status: status as FinancialYearStatusValue };
}

/**
 * Narrow one accounting-period row, or return null when it is not safe to render.
 *
 * Required: id, name, both dates, a known status and `accepts_postings`. The
 * embedded financial year degrades to null when absent or malformed.
 */
export function parseAccountingPeriod(value: unknown): AccountingPeriod | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = asNumber(value.id);
  const name = asString(value.name);
  const start_date = asString(value.start_date);
  const end_date = asString(value.end_date);
  const status = asPeriodStatus(value.status);
  const accepts_postings = asBoolean(value.accepts_postings);
  const financial_year_id = asNullableNumber(value.financial_year_id);

  if (
    id === undefined ||
    name === undefined ||
    start_date === undefined ||
    end_date === undefined ||
    status === undefined ||
    accepts_postings === undefined
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
    financial_year_id,
    financial_year: parseFinancialYearRef(value.financial_year),
    closed_by: asNullableNumber(value.closed_by),
    closed_at: asNullableString(value.closed_at),
    reopened_by: asNullableNumber(value.reopened_by),
    reopened_at: asNullableString(value.reopened_at),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toErrorState(message: string): PeriodListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

/**
 * Narrow the flat (or future paginator-shaped) list payload into a validated
 * `PeriodListState`. Invalid rows are skipped, never fabricated.
 */
export function parseAccountingPeriodList(raw: unknown, perPage: number): PeriodListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toErrorState("The accounting periods response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: AccountingPeriod[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const period = parseAccountingPeriod(entry);

    if (period === null) {
      skipped += 1;
      continue;
    }

    items.push(period);
  }

  if (skipped > 0 && items.length === 0) {
    return toErrorState(
      "The accounting periods response contained no valid period rows.",
    );
  }

  return { status: "ready", items, perPage, hasMore: list.hasMore };
}

/** Narrow a single write-response payload, or throw when malformed. */
export function parseAccountingPeriodOrThrow(
  raw: unknown,
  label = "accounting period",
): AccountingPeriod {
  const period = parseAccountingPeriod(raw);

  if (period === null) {
    throw new PeriodsDataError(`The ${label} response was invalid.`);
  }

  return period;
}

function parseFinding(value: unknown): ControlFinding | null {
  if (!isRecord(value)) {
    return null;
  }

  const control_code = asString(value.control_code);
  const status = asSeverity(value.status);

  if (control_code === undefined || status === undefined) {
    return null;
  }

  return {
    control_code,
    status,
    description: asString(value.description) ?? "",
    resource_type: asNullableString(value.resource_type),
    resource_id: asNullableNumber(value.resource_id),
    financial_date: asNullableString(value.financial_date),
    details: value.details ?? null,
  };
}

function parseFindings(value: unknown): ControlFinding[] {
  if (!Array.isArray(value)) {
    return [];
  }

  const findings: ControlFinding[] = [];

  for (const entry of value) {
    const finding = parseFinding(entry);

    if (finding !== null) {
      findings.push(finding);
    }
  }

  return findings;
}

function parseSummary(value: unknown): ClosingCheckSummary {
  if (!isRecord(value)) {
    return { PASS: 0, WARNING: 0, FAIL: 0 };
  }

  return {
    PASS: asCount(value.PASS),
    WARNING: asCount(value.WARNING),
    FAIL: asCount(value.FAIL),
  };
}

function parseClosingCheckPeriod(value: unknown): ClosingCheckPeriod | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = asNumber(value.id);
  const name = asString(value.name);
  const status = asPeriodStatus(value.status);
  const start_date = asString(value.start_date) ?? "";
  const end_date = asString(value.end_date) ?? "";

  if (id === undefined || name === undefined || status === undefined) {
    return null;
  }

  return {
    id,
    name,
    start_date,
    end_date,
    status,
    financial_year_id: asNullableNumber(value.financial_year_id),
  };
}

/**
 * Narrow the closing-check payload. Returns null only when the decision itself
 * (the period, `eligible` and `already_closed`) is missing; the findings are
 * filtered individually so one unknown control cannot blank the report.
 */
export function parseClosingCheck(raw: unknown): ClosingCheck | null {
  if (!isRecord(raw)) {
    return null;
  }

  const period = parseClosingCheckPeriod(raw.period);
  const eligible = asBoolean(raw.eligible);
  const already_closed = asBoolean(raw.already_closed);

  if (period === null || eligible === undefined || already_closed === undefined) {
    return null;
  }

  return {
    period,
    eligible,
    already_closed,
    summary: parseSummary(raw.summary),
    blocking_findings: parseFindings(raw.blocking_findings),
    warning_findings: parseFindings(raw.warning_findings),
    findings: parseFindings(raw.findings),
    required_action: asNullableString(raw.required_action),
  };
}

export type PeriodValidationErrors = Record<string, string>;

/** `date_format:Y-m-d`. */
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Client-side validation mirroring `StorePeriodRequest` / `UpdatePeriodRequest`.
 *
 * Feedback only — the backend re-validates every write. The financial year is
 * only present on create (`UpdatePeriodRequest` carries no `financial_year_id`).
 */
export function validatePeriodWrite(
  payload: PeriodWritePayload | PeriodUpdatePayload,
): PeriodValidationErrors {
  const errors: PeriodValidationErrors = {};

  if (!payload.name.trim()) {
    errors.name = "The period name is required.";
  } else if (payload.name.length > 255) {
    errors.name = "The period name may not be longer than 255 characters.";
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
    errors.end_date = "The period end date must not be before its start date.";
  }

  if ("financial_year_id" in payload && payload.financial_year_id !== null) {
    if (!Number.isInteger(payload.financial_year_id) || payload.financial_year_id <= 0) {
      errors.financial_year_id = "Select a valid financial year.";
    }
  }

  return errors;
}
