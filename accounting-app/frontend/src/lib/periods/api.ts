"use client";

import { backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type { PeriodStatusValue, PeriodUpdatePayload, PeriodWritePayload } from "./types";

/**
 * Accounting-period backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. The
 * browser never calls Laravel directly and never sends `company_id` — the scope
 * comes from the server-side company context, and a period belonging to another
 * company resolves to 404.
 *
 * List responses are returned as `unknown` and narrowed by `validators.ts`;
 * write and closing-check responses are narrowed by the mutation layer.
 */

export type PeriodListQuery = {
  status?: PeriodStatusValue;
  financial_year_id?: number;
  /** Inclusive lower bound on the period range, `Y-m-d`. */
  from_date?: string;
  /** Inclusive upper bound on the period range, `Y-m-d`. */
  to_date?: string;
  page?: number;
  per_page?: number;
};

export function fetchAccountingPeriods(
  query: PeriodListQuery,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    status: query.status || undefined,
    financial_year_id: query.financial_year_id,
    from_date: query.from_date || undefined,
    to_date: query.to_date || undefined,
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("accounting/periods", { query: params, signal });
}

export function fetchAccountingPeriod(
  periodId: number,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet(`accounting/periods/${periodId}`, { signal });
}

export function createAccountingPeriod(payload: PeriodWritePayload): Promise<unknown> {
  return backendPost("accounting/periods", payload);
}

export function updateAccountingPeriod(
  periodId: number,
  payload: PeriodUpdatePayload,
): Promise<unknown> {
  return backendPut(`accounting/periods/${periodId}`, payload);
}

/** Read-only period-end review: may this period be closed, and if not, why. */
export function fetchPeriodClosingCheck(
  periodId: number,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet(`accounting/periods/${periodId}/closing-check`, { signal });
}

/** Close a period. Refused by the backend while a blocking control finding stands. */
export function closeAccountingPeriod(periodId: number): Promise<unknown> {
  return backendPost(`accounting/periods/${periodId}/close`);
}

/**
 * Reopen a closed period. Does not touch journal history; refused when the
 * period's financial year is closed.
 */
export function reopenAccountingPeriod(periodId: number): Promise<unknown> {
  return backendPost(`accounting/periods/${periodId}/reopen`);
}
