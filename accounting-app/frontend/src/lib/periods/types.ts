/**
 * Accounting-period and period-closing data contracts.
 *
 * These mirror the verified Laravel `AccountingPeriodResource` and the
 * `PeriodClosingCheckService::review()` payload exactly (see
 * `AccountingPeriodController.php`, `PeriodClosingCheckService.php` and
 * `FRONTEND_API_HANDOFF.md`). A period is a dated posting window; `accepts_postings`
 * is derived by the backend from the period's own status AND its financial
 * year's, so the UI reports postability without re-implementing the rule.
 *
 * Payloads for `POST /accounting/periods` and
 * `PUT /accounting/periods/{period}` are written in the snake_case the backend
 * validates. Identifiers, status and the close/reopen audit are server-owned.
 */

import type { FinancialYearStatusValue } from "@/lib/financial-years/types";

export type PeriodStatusValue = "OPEN" | "CLOSED";

export const PERIOD_STATUS_VALUES: readonly PeriodStatusValue[] = [
  "OPEN",
  "CLOSED",
] as const;

/** The compact financial-year reference embedded in a period row. */
export type PeriodFinancialYear = {
  id: number;
  name: string;
  status: FinancialYearStatusValue;
};

/** One row from the verified `AccountingPeriodResource`. */
export type AccountingPeriod = {
  id: number;
  name: string;
  /** Inclusive start, `Y-m-d`. */
  start_date: string;
  /** Inclusive end, `Y-m-d`. */
  end_date: string;
  status: PeriodStatusValue;
  /** True when the period and its year both accept postings. */
  accepts_postings: boolean;
  financial_year_id: number | null;
  financial_year: PeriodFinancialYear | null;
  closed_by: number | null;
  closed_at: string | null;
  reopened_by: number | null;
  reopened_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

/** Client-side list filters; empty means "all". */
export type PeriodFilters = {
  status: PeriodStatusValue | "";
  financial_year_id: number | null;
  from_date: string;
  to_date: string;
};

export const DEFAULT_PERIOD_FILTERS: PeriodFilters = {
  status: "",
  financial_year_id: null,
  from_date: "",
  to_date: "",
};

/** Snapshot of a list page, with the backend's flat-array pagination caveat. */
export type PeriodListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: AccountingPeriod[];
  hasMore: boolean;
  perPage: number;
  message?: string;
  requestId?: string;
};

/**
 * Create body validated by `StorePeriodRequest` (name, dates and the optional
 * company-scoped financial year).
 */
export type PeriodWritePayload = {
  name: string;
  start_date: string;
  end_date: string;
  financial_year_id: number | null;
};

/** Update body validated by `UpdatePeriodRequest` (no financial year move). */
export type PeriodUpdatePayload = {
  name: string;
  start_date: string;
  end_date: string;
};

export type ControlSeverityValue = "PASS" | "WARNING" | "FAIL";

export const CONTROL_SEVERITY_VALUES: readonly ControlSeverityValue[] = [
  "PASS",
  "WARNING",
  "FAIL",
] as const;

/** One `ControlFinding` as serialised by `ControlFinding::toArray()`. */
export type ControlFinding = {
  control_code: string;
  status: ControlSeverityValue;
  description: string;
  resource_type: string | null;
  resource_id: number | null;
  financial_date: string | null;
  details: unknown;
};

/** Finding counts by severity; the keys mirror `ControlStatus` values. */
export type ClosingCheckSummary = {
  PASS: number;
  WARNING: number;
  FAIL: number;
};

/** The lean period reference embedded in a closing-check response. */
export type ClosingCheckPeriod = {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  status: PeriodStatusValue;
  financial_year_id: number | null;
};

/** The verified `GET .../closing-check` payload. */
export type ClosingCheck = {
  period: ClosingCheckPeriod;
  eligible: boolean;
  already_closed: boolean;
  summary: ClosingCheckSummary;
  blocking_findings: ControlFinding[];
  warning_findings: ControlFinding[];
  findings: ControlFinding[];
  required_action: string | null;
};
