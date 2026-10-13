/**
 * Aged payables report types (`GET /accounting/reports/payables-aging`).
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

export type PayablesAgingResponse = {
  as_of: string;
  base_currency: BaseCurrency;
  buckets: AgingBucket[];
  rows: AgingRow[];
  totals: {
    by_bucket: Record<string, string>;
    total: string;
  };
};

export type PayablesAgingFilters = {
  as_of: string;
  supplier_id: number | null;
};

export const DEFAULT_PAYABLES_AGING_FILTERS: PayablesAgingFilters = {
  as_of: "",
  supplier_id: null,
};

export type PayablesAgingState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: PayablesAgingResponse }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
