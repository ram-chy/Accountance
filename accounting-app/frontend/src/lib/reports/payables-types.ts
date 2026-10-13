/**
 * Payables report types (`GET /accounting/reports/payables`).
 *
 * A point-in-time list of posted purchase bills with an outstanding balance,
 * bounded by `as_of`. `days_past_due` and every total come from the backend; the
 * browser recomputes nothing.
 */

import type { BaseCurrency } from "./types";

export type PayablesSupplier = {
  id: number;
  code: string | null;
  name: string | null;
};

export type PayablesRow = {
  bill_id: number;
  bill_number: string | null;
  bill_date: string | null;
  due_date: string | null;
  supplier: PayablesSupplier;
  grand_total: string;
  paid_total: string;
  balance_due: string;
  days_past_due: number | null;
  currency_code?: string | null;
  exchange_rate?: string | null;
  base_grand_total?: string | null;
};

export type PayablesTotals = {
  grand_total: string;
  paid_total: string;
  balance_due: string;
};

export type PayablesResponse = {
  as_of: string;
  base_currency: BaseCurrency;
  rows: PayablesRow[];
  count: number;
  totals: PayablesTotals;
};

export type PayablesFilters = {
  as_of: string;
  supplier_id: number | null;
};

export const DEFAULT_PAYABLES_FILTERS: PayablesFilters = {
  as_of: "",
  supplier_id: null,
};

export type PayablesState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: PayablesResponse }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
