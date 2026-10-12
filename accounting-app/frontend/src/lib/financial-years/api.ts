"use client";

import { backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type { FinancialYearStatusValue, FinancialYearWritePayload } from "./types";

/**
 * Financial-year backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. The
 * browser never calls Laravel directly and never sends `company_id` — the scope
 * comes from the server-side company context, and a year belonging to another
 * company resolves to 404.
 *
 * List responses are returned as `unknown` and narrowed by `validators.ts`;
 * write responses are narrowed by `parseFinancialYear` in the mutation layer.
 */

export type FinancialYearListQuery = {
  status?: FinancialYearStatusValue;
  page?: number;
  per_page?: number;
};

export function fetchFinancialYears(
  query: FinancialYearListQuery,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    status: query.status || undefined,
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("accounting/financial-years", { query: params, signal });
}

export function createFinancialYear(payload: FinancialYearWritePayload): Promise<unknown> {
  return backendPost("accounting/financial-years", payload);
}

export function updateFinancialYear(
  financialYearId: number,
  payload: Partial<FinancialYearWritePayload>,
): Promise<unknown> {
  return backendPut(`accounting/financial-years/${financialYearId}`, payload);
}

/**
 * Generate the monthly accounting periods belonging to a year. Idempotent: a
 * second call over a fully generated year creates nothing and reports it.
 */
export function generateFinancialYearPeriods(financialYearId: number): Promise<unknown> {
  return backendPost(`accounting/financial-years/${financialYearId}/periods/generate`);
}

/**
 * Finalize a financial year. Only permitted once every period is closed; the
 * backend writes no journal and refuses while critical controls fail.
 */
export function closeFinancialYear(financialYearId: number): Promise<unknown> {
  return backendPost(`accounting/financial-years/${financialYearId}/close`);
}
