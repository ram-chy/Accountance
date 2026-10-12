"use client";

import { backendDelete, backendGet, backendPost } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type {
  AddBankReconciliationItemPayload,
  BankReconciliationFilters,
} from "./types";

/**
 * Bank-reconciliation backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. The
 * browser never calls Laravel directly and never sends `company_id` — the scope
 * comes from the server-side company context, and a reconciliation belonging to
 * another company resolves to 404.
 *
 * Read responses are returned as `unknown` and narrowed by `validators.ts`.
 * The create and update endpoints are deliberately not wrapped here: they
 * require the `bank_accounts.id`, which no read endpoint exposes (see the
 * Phase 9 report, "known limitations").
 */

export type BankReconciliationListQuery = BankReconciliationFilters & {
  page?: number;
  per_page?: number;
};

export function fetchBankReconciliations(
  query: BankReconciliationListQuery,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    status: query.status || undefined,
    from_date: query.from_date || undefined,
    to_date: query.to_date || undefined,
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("bank-reconciliations", { query: params, signal });
}

export function fetchBankReconciliation(
  reconciliationId: number,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet(`bank-reconciliations/${reconciliationId}`, { signal });
}

/**
 * The account's movements for the reconciliation period, each flagged with
 * whether it is already cleared into *this* reconciliation. Movements already
 * cleared into an earlier settlement are excluded by the backend.
 */
export function fetchBankReconciliationMovements(
  reconciliationId: number,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet(`bank-reconciliations/${reconciliationId}/movements`, { signal });
}

/** Clear one movement into the reconciliation. Returns the created item id. */
export function addBankReconciliationItem(
  reconciliationId: number,
  payload: AddBankReconciliationItemPayload,
): Promise<unknown> {
  return backendPost(`bank-reconciliations/${reconciliationId}/items`, payload);
}

/**
 * Remove a cleared item. Requires the `bank_reconciliation_items.id`, which the
 * list/detail/movements resources never expose — only the add response returns
 * it, so only items cleared in the current session can be removed.
 */
export function removeBankReconciliationItem(
  reconciliationId: number,
  itemId: number,
): Promise<unknown> {
  return backendDelete(`bank-reconciliations/${reconciliationId}/items/${itemId}`);
}

/** Complete a reconciliation. Refused with a 422 when the difference is non-zero. */
export function completeBankReconciliation(reconciliationId: number): Promise<unknown> {
  return backendPost(`bank-reconciliations/${reconciliationId}/complete`);
}

/** Reopen a completed reconciliation back into the in-progress state. */
export function reopenBankReconciliation(reconciliationId: number): Promise<unknown> {
  return backendPost(`bank-reconciliations/${reconciliationId}/reopen`);
}

/** Delete a reconciliation. Only DRAFT reconciliations are deletable. */
export function deleteBankReconciliation(reconciliationId: number): Promise<unknown> {
  return backendDelete(`bank-reconciliations/${reconciliationId}`);
}
