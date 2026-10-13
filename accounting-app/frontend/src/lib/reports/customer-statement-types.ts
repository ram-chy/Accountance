/**
 * Customer statement report types.
 *
 * Mirrors `CounterpartyStatementReportService::build()` reached through
 * `GET /accounting/reports/customer-statement`. Money is a decimal string in the
 * base currency; foreign rows additionally carry `currency_code`,
 * `exchange_rate` and `foreign_amount`.
 */

import type { BaseCurrency } from "./types";

export type CustomerStatementCustomer = {
  id: number;
  code: string | null;
  name: string | null;
};

export type CustomerStatementRow = {
  date: string | null;
  type: string;
  reference: string | null;
  description: string | null;
  debit: string;
  credit: string;
  running_balance: string;
  currency_code?: string | null;
  exchange_rate?: string | null;
  foreign_amount?: string | null;
};

export type CustomerStatementTotals = {
  debit: string;
  credit: string;
};

export type CustomerStatementResponse = {
  customer: CustomerStatementCustomer;
  period: { from: string | null; to: string | null };
  base_currency: BaseCurrency;
  opening_balance: string;
  rows: CustomerStatementRow[];
  totals: CustomerStatementTotals;
  closing_balance: string;
  [key: string]: unknown;
};

export type CustomerStatementFilters = {
  customer_id: number | null;
  from: string;
  to: string;
};

export const DEFAULT_CUSTOMER_STATEMENT_FILTERS: CustomerStatementFilters = {
  customer_id: null,
  from: "",
  to: "",
};

export type CustomerStatementState =
  | { status: "idle"; data: null }
  | { status: "loading"; data: null }
  | { status: "ready"; data: CustomerStatementResponse }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
