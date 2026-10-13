/**
 * Trial Balance report types.
 */

import type { BaseCurrency, ReportPeriod } from "./types";

export type TrialBalanceRow = {
  account_id: number;
  code: string;
  name: string;
  account_type: string;
  debit_total: string;
  credit_total: string;
  net_balance: string;
};

export type TrialBalanceTotals = {
  debit_total: string;
  credit_total: string;
  net_balance: string;
  is_balanced: boolean;
  difference: string;
};

export type TrialBalanceResponse = {
  period: ReportPeriod;
  base_currency: BaseCurrency;
  rows: TrialBalanceRow[];
  totals: TrialBalanceTotals;
  include_zero_balances: boolean;
};

export type TrialBalanceFilters = {
  from: string;
  to: string;
  include_zero_balances: boolean;
};

export const DEFAULT_TRIAL_BALANCE_FILTERS: TrialBalanceFilters = {
  from: "",
  to: "",
  include_zero_balances: false,
};

export type TrialBalanceState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: TrialBalanceResponse }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
