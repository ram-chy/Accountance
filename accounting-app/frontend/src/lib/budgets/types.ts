/**
 * Budget data contracts.
 *
 * These mirror the verified Laravel `BudgetResource`, `BudgetLineResource` and
 * the `BudgetVarianceReportService::generate()` payload exactly (see
 * `BudgetController.php`, `BudgetService.php`, `BudgetLineService.php`,
 * `BudgetVarianceReportService.php` and the routes in `routes/api.php`).
 *
 * A budget is a plan, not an accounting record. Its lines carry a planned
 * `amount` as a canonical 4-decimal string, and the actuals a variance report
 * measures them against are derived from posted journals at request time — they
 * are never stored here and never computed in the browser.
 *
 * Payloads for the budget and budget-line endpoints are written in the
 * snake_case the backend validates. Identifiers, the company, the version chain
 * and the status are server-owned and are never sent by the browser.
 */

import type { AccountTypeValue } from "@/lib/accounts/types";
import type { FinancialYearStatusValue } from "@/lib/financial-years/types";
import type { PeriodStatusValue } from "@/lib/periods/types";
import type { BaseCurrency, ReportPeriod } from "@/lib/reports/types";

export type BudgetStatusValue = "DRAFT" | "APPROVED";

export const BUDGET_STATUS_VALUES: readonly BudgetStatusValue[] = ["DRAFT", "APPROVED"] as const;

export const BUDGET_STATUS_LABELS: Record<BudgetStatusValue, string> = {
  DRAFT: "Draft",
  APPROVED: "Approved",
};

export type BudgetVarianceStatusValue = "FAVOURABLE" | "UNFAVOURABLE" | "ON_TARGET";

export const BUDGET_VARIANCE_STATUS_VALUES: readonly BudgetVarianceStatusValue[] = [
  "FAVOURABLE",
  "UNFAVOURABLE",
  "ON_TARGET",
] as const;

export const BUDGET_VARIANCE_STATUS_LABELS: Record<BudgetVarianceStatusValue, string> = {
  FAVOURABLE: "Favourable",
  UNFAVOURABLE: "Unfavourable",
  ON_TARGET: "On target",
};

/** The compact financial-year reference embedded in a budget. */
export type BudgetFinancialYearRef = {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  status: FinancialYearStatusValue;
};

/** The compact account reference embedded in a budget line. */
export type BudgetAccountRef = {
  id: number;
  code: string;
  name: string;
  account_type: AccountTypeValue;
  is_active: boolean;
};

/** The compact accounting-period reference embedded in a budget line. */
export type BudgetPeriodRef = {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  status: PeriodStatusValue;
  financial_year_id: number | null;
};

/**
 * One analytical label on a budget line: a value of a dimension. Serialised by
 * the backend as `{ dimension_id, value_id }`.
 */
export type BudgetLineDimensionAssignment = {
  dimension_id: number;
  value_id: number;
};

/** One row from the verified `BudgetLineResource`. */
export type BudgetLine = {
  id: number;
  budget_id: number;
  account_id: number;
  accounting_period_id: number;
  /** Canonical 4-decimal string; never a float. */
  amount: string;
  description: string | null;
  /** Present when the controller loaded the account. */
  account?: BudgetAccountRef | null;
  /** Present when the controller loaded the period. */
  accounting_period?: BudgetPeriodRef | null;
  /** Present when the controller loaded the line's dimension labels. */
  dimensions?: BudgetLineDimensionAssignment[];
  created_at: string | null;
  updated_at: string | null;
};

/** One row from the verified `BudgetResource`. */
export type Budget = {
  id: number;
  company_id: number;
  financial_year_id: number;
  code: string;
  name: string;
  version_number: number;
  parent_budget_id: number | null;
  status: BudgetStatusValue;
  notes: string | null;
  created_by: number | null;
  updated_by: number | null;
  approved_by: number | null;
  approved_at: string | null;
  /** Present only when the controller loaded the year. */
  financial_year?: BudgetFinancialYearRef | null;
  /** Present only when the controller loaded the lines. */
  lines?: BudgetLine[];
  /** Present only when the controller counted the lines. */
  lines_count?: number | null;
  created_at: string | null;
  updated_at: string | null;
};

/** Client-side list filters; empty means "all". */
export type BudgetListFilters = {
  financial_year_id?: number;
  status?: BudgetStatusValue;
  search?: string;
  per_page?: number;
  page?: number;
};

/** Client-side variance filters; null means "unfiltered". */
export type BudgetVarianceFilters = {
  dimension_id: number | null;
  dimension_value_id: number | null;
};

/** Snapshot of a budget list page. */
export type BudgetListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: Budget[];
  perPage: number;
  hasMore: boolean;
  message?: string;
  requestId?: string;
};

/** Snapshot of a single budget with its lines. */
export type BudgetDetailState = {
  status: "loading" | "ready" | "error" | "unavailable" | "notfound";
  budget: Budget | null;
  message?: string;
  requestId?: string;
};

/** Create body validated by `StoreBudgetRequest`. */
export type BudgetCreatePayload = {
  financial_year_id: number;
  code: string;
  name: string;
  notes: string | null;
};

/** Update body validated by `UpdateBudgetRequest` (the year is fixed). */
export type BudgetUpdatePayload = Partial<{
  code: string;
  name: string;
  notes: string | null;
}>;

/** Revise body validated by `ReviseBudgetRequest`. */
export type BudgetRevisePayload = {
  name?: string;
  notes?: string | null;
};

/**
 * Create body validated by `StoreBudgetLineRequest`. `amount` is a non-negative
 * decimal string; `dimensions` is the line's whole set of labels.
 */
export type BudgetLineCreatePayload = {
  account_id: number;
  accounting_period_id: number;
  amount: string;
  description: string | null;
  dimensions: BudgetLineDimensionAssignment[];
};

/**
 * Update body validated by `UpdateBudgetLineRequest`. Every field is optional; a
 * present `dimensions` — even `[]` — replaces the line's whole label set.
 */
export type BudgetLineUpdatePayload = Partial<{
  account_id: number;
  accounting_period_id: number;
  amount: string;
  description: string | null;
  dimensions: BudgetLineDimensionAssignment[];
}>;

/** The `budget` header echoed by the variance report. */
export type BudgetVarianceBudgetRef = {
  id: number;
  code: string;
  name: string;
  version_number: number;
  status: BudgetStatusValue;
  financial_year: {
    id: number | null;
    name: string | null;
    start_date: string | null;
    end_date: string | null;
  };
};

export type BudgetVarianceSummarySection = {
  budget: string;
  actual: string;
  variance: string;
};

export type BudgetVarianceNet = BudgetVarianceSummarySection & {
  status: BudgetVarianceStatusValue;
};

export type BudgetVarianceCounts = {
  favourable: number;
  unfavourable: number;
  on_target: number;
};

/** One account/period row of the variance table. */
export type BudgetVarianceLine = {
  account_id: number;
  account_code: string;
  account_name: string;
  account_type: AccountTypeValue;
  accounting_period_id: number;
  period_name: string;
  period_start: string;
  period_end: string;
  budget: string;
  actual: string;
  variance: string;
  /** 4-decimal string, or null when the plan was zero (a percentage of zero). */
  variance_percentage: string | null;
  status: BudgetVarianceStatusValue;
};

/** The verified `GET /accounting/budgets/{budget}/variance` payload. */
export type BudgetVarianceReport = {
  budget: BudgetVarianceBudgetRef;
  period: ReportPeriod;
  base_currency: BaseCurrency;
  summary: {
    revenue: BudgetVarianceSummarySection;
    expenses: BudgetVarianceSummarySection;
    net: BudgetVarianceNet;
    counts: BudgetVarianceCounts;
  };
  lines: BudgetVarianceLine[];
  dimension_filter?: {
    dimension_id: number | null;
    dimension_value_id: number | null;
  };
};

/** Snapshot of the variance report request. */
export type BudgetVarianceState = {
  status: "loading" | "ready" | "error" | "unavailable" | "notfound";
  data: BudgetVarianceReport | null;
  message?: string;
  requestId?: string;
};
