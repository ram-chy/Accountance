/**
 * API layer for financial reports.
 */

import { backendGet } from "@/lib/api/backend";

import type { TrialBalanceResponse, TrialBalanceFilters } from "./trial-balance-types";
import type { ProfitLossResponse, ProfitLossFilters } from "./profit-loss-types";
import type { GeneralLedgerResponse, GeneralLedgerFilters } from "./general-ledger-types";
import type { BalanceSheetResponse, BalanceSheetFilters } from "./balance-sheet-types";
import type { CashBankResponse, CashBankFilters } from "./cash-bank-report-types";
import type { CustomerStatementFilters, CustomerStatementResponse } from "./customer-statement-types";
import type { ReceivablesFilters, ReceivablesResponse } from "./receivables-types";
import type { ReceivablesAgingFilters, ReceivablesAgingResponse } from "./receivables-aging-types";
import type { SupplierStatementFilters, SupplierStatementResponse } from "./supplier-statement-types";
import type { PayablesFilters, PayablesResponse } from "./payables-types";
import type { PayablesAgingFilters, PayablesAgingResponse } from "./payables-aging-types";

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

/**
 * Customer account statement. `customer_id` is required by the backend.
 */
export function fetchCustomerStatement(filters: CustomerStatementFilters, signal?: AbortSignal) {
  const params = new URLSearchParams();
  if (filters.customer_id !== null) params.set("customer_id", String(filters.customer_id));
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  const qs = params.toString();
  const path = `accounting/reports/customer-statement${qs ? `?${qs}` : ""}`;
  return backendGet<CustomerStatementResponse>(path, { signal });
}

/**
 * Outstanding receivables as of a date. `customer_id` is optional.
 */
export function fetchReceivables(filters: ReceivablesFilters, signal?: AbortSignal) {
  const params = new URLSearchParams();
  if (filters.customer_id !== null) params.set("customer_id", String(filters.customer_id));
  if (filters.as_of) params.set("as_of", filters.as_of);
  const qs = params.toString();
  const path = `accounting/reports/receivables${qs ? `?${qs}` : ""}`;
  return backendGet<ReceivablesResponse>(path, { signal });
}

/**
 * Aged receivables as of a date. `customer_id` is optional.
 */
export function fetchReceivablesAging(filters: ReceivablesAgingFilters, signal?: AbortSignal) {
  const params = new URLSearchParams();
  if (filters.customer_id !== null) params.set("customer_id", String(filters.customer_id));
  if (filters.as_of) params.set("as_of", filters.as_of);
  const qs = params.toString();
  const path = `accounting/reports/receivables-aging${qs ? `?${qs}` : ""}`;
  return backendGet<ReceivablesAgingResponse>(path, { signal });
}

/**
 * Supplier account statement. `supplier_id` is required by the backend.
 */
export function fetchSupplierStatement(filters: SupplierStatementFilters, signal?: AbortSignal) {
  const params = new URLSearchParams();
  if (filters.supplier_id !== null) params.set("supplier_id", String(filters.supplier_id));
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  const qs = params.toString();
  const path = `accounting/reports/supplier-statement${qs ? `?${qs}` : ""}`;
  return backendGet<SupplierStatementResponse>(path, { signal });
}

/**
 * Outstanding payables as of a date. `supplier_id` is optional.
 */
export function fetchPayables(filters: PayablesFilters, signal?: AbortSignal) {
  const params = new URLSearchParams();
  if (filters.supplier_id !== null) params.set("supplier_id", String(filters.supplier_id));
  if (filters.as_of) params.set("as_of", filters.as_of);
  const qs = params.toString();
  const path = `accounting/reports/payables${qs ? `?${qs}` : ""}`;
  return backendGet<PayablesResponse>(path, { signal });
}

/**
 * Aged payables as of a date. `supplier_id` is optional.
 */
export function fetchPayablesAging(filters: PayablesAgingFilters, signal?: AbortSignal) {
  const params = new URLSearchParams();
  if (filters.supplier_id !== null) params.set("supplier_id", String(filters.supplier_id));
  if (filters.as_of) params.set("as_of", filters.as_of);
  const qs = params.toString();
  const path = `accounting/reports/payables-aging${qs ? `?${qs}` : ""}`;
  return backendGet<PayablesAgingResponse>(path, { signal });
}
