/**
 * Financial-year data contracts.
 *
 * These mirror the verified Laravel `FinancialYearResource` exactly (see
 * `FinancialYearController.php` / `FinancialYearResource.php` and
 * `FRONTEND_API_HANDOFF.md`). A financial year is a dated organisational
 * container only — it holds no balances and closing it writes no journal — so
 * the resource exposes derived counts (`periods_count`, `open_periods_count`)
 * and the backend's own `can_close` eligibility flag rather than any amount.
 *
 * Payloads for `POST /accounting/financial-years` and
 * `PUT /accounting/financial-years/{financialYear}` are written in the
 * snake_case the backend validates. Identifiers, company scope, status and the
 * close audit are server-owned and never sent by the browser.
 */

export type FinancialYearStatusValue = "OPEN" | "CLOSED";

export const FINANCIAL_YEAR_STATUS_VALUES: readonly FinancialYearStatusValue[] = [
  "OPEN",
  "CLOSED",
] as const;

/** One row from the verified `FinancialYearResource`. */
export type FinancialYear = {
  id: number;
  name: string;
  /** Inclusive start, `Y-m-d`. */
  start_date: string;
  /** Inclusive end, `Y-m-d`. */
  end_date: string;
  status: FinancialYearStatusValue;
  /** True while the year accepts postings (always true when OPEN). */
  accepts_postings: boolean;
  /** Number of accounting periods attached to the year. */
  periods_count: number;
  /** How many of those periods are still OPEN. */
  open_periods_count: number;
  /** Backend-stated eligibility: open year, at least one period, none open. */
  can_close: boolean;
  created_by: number | null;
  closed_by: number | null;
  closed_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

/** Client-side list filters; empty means "all". */
export type FinancialYearFilters = {
  status: FinancialYearStatusValue | "";
};

export const DEFAULT_FINANCIAL_YEAR_FILTERS: FinancialYearFilters = {
  status: "",
};

/** Snapshot of a list page, with the backend's flat-array pagination caveat. */
export type FinancialYearListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: FinancialYear[];
  hasMore: boolean;
  perPage: number;
  message?: string;
  requestId?: string;
};

/** Create/update body validated by `StoreFinancialYearRequest`. */
export type FinancialYearWritePayload = {
  name: string;
  start_date: string;
  end_date: string;
};

/** A compact financial-year reference for a selector. */
export type FinancialYearOption = {
  id: number;
  name: string;
  status: FinancialYearStatusValue;
  start_date: string;
};

/** Response of `POST accounting/financial-years/{financialYear}/periods/generate`. */
export type GeneratePeriodsResult = {
  financial_year: FinancialYear | null;
  periods_count: number;
};
