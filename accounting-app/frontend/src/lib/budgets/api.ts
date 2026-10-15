"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type {
  BudgetCreatePayload,
  BudgetLineCreatePayload,
  BudgetLineUpdatePayload,
  BudgetListFilters,
  BudgetRevisePayload,
  BudgetUpdatePayload,
  BudgetVarianceFilters,
} from "./types";

/**
 * Budget backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. The
 * browser never sends `company_id`, a status, a version number or a parent id —
 * those are server-owned. Approval and revision are their own POST acts, because
 * an approved budget is immutable through `PUT`.
 *
 * Budget lines are addressed under their budget (`/{budget}/lines/{line}`); a
 * line has no company of its own and is resolved through its budget.
 *
 * List/write responses are returned as `unknown` and narrowed by `validators.ts`.
 */

/** Drop `undefined` values so a partial update does not send absent keys. */
function compact(payload: Record<string, unknown>): Record<string, unknown> {
  const body: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(payload)) {
    if (value === undefined) continue;
    body[key] = value;
  }
  return body;
}

function toListParams(query: BudgetListFilters): QueryParams {
  return {
    financial_year_id: query.financial_year_id,
    status: query.status ?? undefined,
    search: query.search || undefined,
    per_page: query.per_page,
    page: query.page,
  };
}

/** `GET /accounting/budgets` */
export function fetchBudgets(query: BudgetListFilters, signal?: AbortSignal): Promise<unknown> {
  return backendGet("accounting/budgets", { query: toListParams(query), signal });
}

/** `GET /accounting/budgets/{id}` — loads the year and the lines. */
export function fetchBudget(id: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`accounting/budgets/${id}`, { signal });
}

/** `POST /accounting/budgets` (HTTP 201; server sets DRAFT/version 1). */
export function createBudget(payload: BudgetCreatePayload): Promise<unknown> {
  return backendPost("accounting/budgets", compact(payload));
}

/** `PUT /accounting/budgets/{id}` — draft only. */
export function updateBudget(id: number, payload: BudgetUpdatePayload): Promise<unknown> {
  return backendPut(`accounting/budgets/${id}`, compact(payload));
}

/** `DELETE /accounting/budgets/{id}` — draft only. */
export function deleteBudget(id: number): Promise<unknown> {
  return backendDelete(`accounting/budgets/${id}`);
}

/** `POST /accounting/budgets/{id}/approve` (no body; refuses an empty budget). */
export function approveBudget(id: number): Promise<unknown> {
  return backendPost(`accounting/budgets/${id}/approve`);
}

/** `POST /accounting/budgets/{id}/revise` (HTTP 201; approved only). */
export function reviseBudget(id: number, payload: BudgetRevisePayload): Promise<unknown> {
  return backendPost(`accounting/budgets/${id}/revise`, compact(payload));
}

/** `GET /accounting/budgets/{id}/variance` with the optional dimension filter. */
export function fetchBudgetVariance(
  id: number,
  filters: BudgetVarianceFilters,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    dimension_id: filters.dimension_id ?? undefined,
    dimension_value_id: filters.dimension_value_id ?? undefined,
  };
  return backendGet(`accounting/budgets/${id}/variance`, { query: params, signal });
}

/** `POST /accounting/budgets/{id}/lines` (HTTP 201; draft only). */
export function createBudgetLine(budgetId: number, payload: BudgetLineCreatePayload): Promise<unknown> {
  return backendPost(`accounting/budgets/${budgetId}/lines`, compact(payload));
}

/** `PUT /accounting/budgets/{id}/lines/{line}` — draft only; `dimensions` replaces. */
export function updateBudgetLine(
  budgetId: number,
  lineId: number,
  payload: BudgetLineUpdatePayload,
): Promise<unknown> {
  return backendPut(`accounting/budgets/${budgetId}/lines/${lineId}`, compact(payload));
}

/** `DELETE /accounting/budgets/{id}/lines/{line}` — draft only. */
export function deleteBudgetLine(budgetId: number, lineId: number): Promise<unknown> {
  return backendDelete(`accounting/budgets/${budgetId}/lines/${lineId}`);
}
