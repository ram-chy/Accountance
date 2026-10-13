/**
 * Balance Sheet report types.
 *
 * Mirrors `BalanceSheetReportService::generate()` exactly. The sheet is
 * cumulative through `to` (the as-of date); `from` is optional and only labels
 * the informational `current_period_result` line.
 */

import type { BaseCurrency, ReportPeriod } from "./types";

/**
 * A signed account row from `JournalReportService::signedRowsForType()`:
 * `{ account_id, code, name, amount }`. No `account_type` field is returned —
 * the section the row sits in (assets / liabilities / equity) is the type.
 */
export type BalanceSheetAccountRow = {
  account_id: number;
  code: string;
  name: string;
  amount: string;
};

export type BalanceSheetSection = {
  total: string;
  accounts: BalanceSheetAccountRow[];
};

export type BalanceSheetEquitySection = BalanceSheetSection & {
  /** Cumulative net profit through the as-of date. Already inside `total`. */
  retained_earnings: string;
};

export type BalanceSheetTotals = {
  assets: string;
  liabilities: string;
  equity: string;
  liabilities_and_equity: string;
  is_balanced: boolean;
  difference: string;
};

export type BalanceSheetResponse = {
  period: ReportPeriod;
  base_currency: BaseCurrency;
  assets: BalanceSheetSection;
  liabilities: BalanceSheetSection;
  equity: BalanceSheetEquitySection;
  totals: BalanceSheetTotals;
  /** Informational only — never added into equity by the backend. */
  current_period_result: string;
};

/**
 * The balance sheet is point-in-time. `to` is the as-of date; `from` is
 * optional and only labels the informational current-period result. The UI
 * exposes the as-of date alone and leaves `from` empty.
 */
export type BalanceSheetFilters = {
  from: string;
  to: string;
};

export const DEFAULT_BALANCE_SHEET_FILTERS: BalanceSheetFilters = {
  from: "",
  to: "",
};

export type BalanceSheetState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: BalanceSheetResponse }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
