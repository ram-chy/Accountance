/**
 * Receivables report types (`GET /accounting/reports/receivables`).
 *
 * A point-in-time list of posted invoices with an outstanding balance, bounded
 * by `as_of`. `days_past_due` and every total come from the backend; the browser
 * recomputes nothing.
 */

import type { BaseCurrency } from "./types";

export type ReceivablesCustomer = {
  id: number;
  code: string | null;
  name: string | null;
};

export type ReceivablesRow = {
  invoice_id: number;
  invoice_number: string | null;
  invoice_date: string | null;
  due_date: string | null;
  customer: ReceivablesCustomer;
  grand_total: string;
  paid_total: string;
  balance_due: string;
  days_past_due: number | null;
  currency_code?: string | null;
  exchange_rate?: string | null;
  base_grand_total?: string | null;
};

export type ReceivablesTotals = {
  grand_total: string;
  paid_total: string;
  balance_due: string;
};

export type ReceivablesResponse = {
  as_of: string;
  base_currency: BaseCurrency;
  rows: ReceivablesRow[];
  count: number;
  totals: ReceivablesTotals;
};

export type ReceivablesFilters = {
  as_of: string;
  customer_id: number | null;
};

export const DEFAULT_RECEIVABLES_FILTERS: ReceivablesFilters = {
  as_of: "",
  customer_id: null,
};

export type ReceivablesState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: ReceivablesResponse }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
