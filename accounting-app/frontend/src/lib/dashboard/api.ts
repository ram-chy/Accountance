"use client";

import { backendGet } from "@/lib/api/backend";

/**
 * Dashboard backend calls.
 *
 * Every request goes through the Phase 3 same-origin proxy (`/api/backend/*`),
 * which attaches the server-held bearer token and the active `X-Company-Id`.
 * Responses are returned as `unknown` and narrowed by `validators.ts`; the
 * functions here never interpret the payload.
 */

export type DateWindow = { from: string | null; to: string | null };

export function fetchProfitLoss(
  window: DateWindow,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet("accounting/reports/profit-loss", {
    query: {
      from_date: window.from ?? undefined,
      to_date: window.to ?? undefined,
    },
    signal,
  });
}

export function fetchReceivables(
  asOf: string | null,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet("accounting/reports/receivables", {
    query: { as_of: asOf ?? undefined },
    signal,
  });
}

export function fetchPayables(
  asOf: string | null,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet("accounting/reports/payables", {
    query: { as_of: asOf ?? undefined },
    signal,
  });
}

export function fetchCashBankAccounts(signal?: AbortSignal): Promise<unknown> {
  return backendGet("cash-bank-accounts", { signal });
}

export function fetchAccountBalance(
  accountId: number,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet(`accounting/accounts/${accountId}/balance`, { signal });
}

export function fetchRecentJournals(
  window: DateWindow,
  limit: number,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet("journals", {
    query: {
      status: "POSTED",
      from: window.from ?? undefined,
      to: window.to ?? undefined,
      per_page: limit,
      page: 1,
    },
    signal,
  });
}
