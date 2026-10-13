/**
 * Profit & Loss report types.
 */

import type { BaseCurrency, ReportPeriod } from "./types";

/**
 * A signed account row.
 *
 * The backend builds these in `JournalReportService::signedRowsForType()` and
 * returns exactly `{ account_id, code, name, amount }`. There is deliberately
 * no `account_type` field on the row — the section a row belongs to (revenue or
 * expenses) is the classification — so requiring one would reject every real
 * response.
 */
export type PLAccountRow = {
  account_id: number;
  code: string;
  name: string;
  amount: string;
};

export type PLSection = {
  total: string;
  accounts: PLAccountRow[];
};

export type PLDimensionFilter = {
  dimension_id: number | null;
  dimension_value_id: number | null;
  label: string | null;
};

export type ProfitLossResponse = {
  period: ReportPeriod;
  base_currency: BaseCurrency;
  revenue: PLSection;
  expenses: PLSection;
  gross_profit: string;
  net_profit: string;
  is_profitable: boolean;
  dimension_filter?: PLDimensionFilter;
  unassigned?: {
    revenue: string;
    expenses: string;
    net: string;
  };
};

export type ProfitLossFilters = {
  from: string;
  to: string;
};

export const DEFAULT_PROFIT_LOSS_FILTERS: ProfitLossFilters = {
  from: "",
  to: "",
};

export type ProfitLossState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: ProfitLossResponse }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
