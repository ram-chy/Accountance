/**
 * Supplier statement report types.
 *
 * Mirrors `CounterpartyStatementReportService::build()` reached through
 * `GET /accounting/reports/supplier-statement`. Money is a decimal string in the
 * base currency; foreign rows additionally carry `currency_code`,
 * `exchange_rate` and `foreign_amount`. On the supplier side, bills are credits
 * and payments are debits, and the running balance is payable-positive.
 */

import type { BaseCurrency } from "./types";

export type SupplierStatementSupplier = {
  id: number;
  code: string | null;
  name: string | null;
};

export type SupplierStatementRow = {
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

export type SupplierStatementTotals = {
  debit: string;
  credit: string;
};

export type SupplierStatementResponse = {
  supplier: SupplierStatementSupplier;
  period: { from: string | null; to: string | null };
  base_currency: BaseCurrency;
  opening_balance: string;
  rows: SupplierStatementRow[];
  totals: SupplierStatementTotals;
  closing_balance: string;
  [key: string]: unknown;
};

export type SupplierStatementFilters = {
  supplier_id: number | null;
  from: string;
  to: string;
};

export const DEFAULT_SUPPLIER_STATEMENT_FILTERS: SupplierStatementFilters = {
  supplier_id: null,
  from: "",
  to: "",
};

export type SupplierStatementState =
  | { status: "idle"; data: null }
  | { status: "loading"; data: null }
  | { status: "ready"; data: SupplierStatementResponse }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
