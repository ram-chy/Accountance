/**
 * Aged receivables report types (`GET /accounting/reports/receivables-aging`).
 *
 * Buckets are data-driven: the backend reads their keys, labels and bounds from
 * config, so the view renders whatever `buckets[]` it is given and looks up each
 * counterparty cell by `key` rather than assuming a fixed set of columns.
 */

import type { BaseCurrency } from "./types";

export type AgingBucket = {
  key: string;
  label: string;
  min: number | null;
  max: number | null;
  total: string;
};

export type AgingCounterparty = {
  id: number | null;
  code: string | null;
  name: string | null;
};

export type AgingRow = {
  counterparty: AgingCounterparty;
  buckets: Record<string, string>;
  total: string;
};

export type ReceivablesAgingResponse = {
  as_of: string;
  base_currency: BaseCurrency;
  buckets: AgingBucket[];
  rows: AgingRow[];
  totals: {
    by_bucket: Record<string, string>;
    total: string;
  };
};

export type ReceivablesAgingFilters = {
  as_of: string;
  customer_id: number | null;
};

export const DEFAULT_RECEIVABLES_AGING_FILTERS: ReceivablesAgingFilters = {
  as_of: "",
  customer_id: null,
};

export type ReceivablesAgingState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: ReceivablesAgingResponse }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
