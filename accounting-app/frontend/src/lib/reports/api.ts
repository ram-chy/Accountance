/**
 * API layer for financial reports.
 */

import { backendGet } from "@/lib/api/backend";

import type { TrialBalanceResponse, TrialBalanceFilters } from "./trial-balance-types";
import type { ProfitLossResponse, ProfitLossFilters } from "./profit-loss-types";
import type { GeneralLedgerResponse, GeneralLedgerFilters } from "./general-ledger-types";
import type { BalanceSheetResponse, BalanceSheetFilters } from "./balance-sheet-types";
import type { CashBankResponse, CashBankFilters } from "./cash-bank-report-types";

export function fetchTrialBalance(filters: TrialBalanceFilters, signal?: AbortSignal) {
  const params = new URLSearchParams();
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  if (filters.include_zero_balances) params.set("include_zero_balances", "1");
  const qs = params.toString();
  const path = `accounting/reports/trial-balance${qs ? `?${qs}` : ""}`;
  return backendGet<TrialBalanceResponse>(path, { signal });
}

export function fetchProfitLoss(filters: ProfitLossFilters, signal?: AbortSignal) {
  const params = new URLSearchParams();
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  const qs = params.toString();
  const path = `accounting/reports/profit-loss${qs ? `?${qs}` : ""}`;
  return backendGet<ProfitLossResponse>(path, { signal });
}

export function fetchGeneralLedger(filters: GeneralLedgerFilters, signal?: AbortSignal) {
  const params = new URLSearchParams();
  if (filters.account_id !== null && filters.account_id !== undefined) {
    params.set("account_id", String(filters.account_id));
  }
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  const qs = params.toString();
  const path = `accounting/reports/general-ledger${qs ? `?${qs}` : ""}`;
  return backendGet<GeneralLedgerResponse>(path, { signal });
}

export function fetchBalanceSheet(filters: BalanceSheetFilters, signal?: AbortSignal) {
  const params = new URLSearchParams();
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  const qs = params.toString();
  const path = `accounting/reports/balance-sheet${qs ? `?${qs}` : ""}`;
  return backendGet<BalanceSheetResponse>(path, { signal });
}

/**
 * The cash/bank endpoint returns the general-ledger shape. `account_id` is
 * required by the backend request, so it is always sent when present.
 */
export function fetchCashBank(filters: CashBankFilters, signal?: AbortSignal) {
  const params = new URLSearchParams();
  if (filters.account_id !== null && filters.account_id !== undefined) {
    params.set("account_id", String(filters.account_id));
  }
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  const qs = params.toString();
  const path = `accounting/reports/cash-bank${qs ? `?${qs}` : ""}`;
  return backendGet<CashBankResponse>(path, { signal });
}
