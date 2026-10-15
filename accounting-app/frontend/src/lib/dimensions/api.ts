"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type {
  FinancialDimensionCreatePayload,
  FinancialDimensionListFilters,
  FinancialDimensionUpdatePayload,
  FinancialDimensionValueListFilters,
  FinancialDimensionValueWritePayload,
} from "./types";

/**
 * Financial-dimension backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. The
 * browser never calls Laravel directly and never sends `company_id` — a
 * dimension is always resolved through the caller's own company context, and a
 * foreign id 404s.
 *
 * Values are nested under their dimension everywhere (`/{dimension}/values/{value}`)
 * because a value has no company of its own; resolving it through the parent is
 * both the tenant check and the nesting check in one lookup.
 *
 * List/write responses are returned as `unknown` and narrowed by `validators.ts`.
 */

function toListParams(query: FinancialDimensionListFilters): QueryParams {
  return {
    type: query.type ?? undefined,
    // Three-state: undefined omits `is_active` entirely (backend treats an
    // absent key as "both"); otherwise the 1/0 the backend's boolean cast reads.
    is_active: query.is_active === undefined ? undefined : query.is_active ? "1" : "0",
    search: query.search || undefined,
    per_page: query.per_page,
    page: query.page,
  };
}

function toValueListParams(query: FinancialDimensionValueListFilters): QueryParams {
  return {
    is_active: query.is_active === undefined ? undefined : query.is_active ? "1" : "0",
    search: query.search || undefined,
    per_page: query.per_page,
    page: query.page,
  };
}

/** `GET /accounting/dimensions` */
export function fetchFinancialDimensions(
  query: FinancialDimensionListFilters,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet("accounting/dimensions", { query: toListParams(query), signal });
}

/** `GET /accounting/dimensions/{id}` — loads the dimension's values. */
export function fetchFinancialDimension(id: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`accounting/dimensions/${id}`, { signal });
}

/** `POST /accounting/dimensions` (HTTP 201) */
export function createFinancialDimension(payload: FinancialDimensionCreatePayload): Promise<unknown> {
  return backendPost("accounting/dimensions", payload);
}

/** `PUT /accounting/dimensions/{id}` — code/name only; the type is fixed. */
export function updateFinancialDimension(
  id: number,
  payload: FinancialDimensionUpdatePayload,
): Promise<unknown> {
  return backendPut(`accounting/dimensions/${id}`, payload);
}

/** `DELETE /accounting/dimensions/{id}` — refused while a value is referenced. */
export function deleteFinancialDimension(id: number): Promise<unknown> {
  return backendDelete(`accounting/dimensions/${id}`);
}

/** `POST /accounting/dimensions/{id}/activate` (no body). */
export function activateFinancialDimension(id: number): Promise<unknown> {
  return backendPost(`accounting/dimensions/${id}/activate`);
}

/** `POST /accounting/dimensions/{id}/deactivate` (no body). */
export function deactivateFinancialDimension(id: number): Promise<unknown> {
  return backendPost(`accounting/dimensions/${id}/deactivate`);
}

/** `GET /accounting/dimensions/{id}/values` */
export function fetchFinancialDimensionValues(
  id: number,
  query: FinancialDimensionValueListFilters,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet(`accounting/dimensions/${id}/values`, { query: toValueListParams(query), signal });
}

/** `POST /accounting/dimensions/{id}/values` (HTTP 201; parent must be active). */
export function createFinancialDimensionValue(
  id: number,
  payload: FinancialDimensionValueWritePayload,
): Promise<unknown> {
  return backendPost(`accounting/dimensions/${id}/values`, payload);
}

/** `PUT /accounting/dimensions/{id}/values/{value}` */
export function updateFinancialDimensionValue(
  id: number,
  valueId: number,
  payload: FinancialDimensionValueWritePayload,
): Promise<unknown> {
  return backendPut(`accounting/dimensions/${id}/values/${valueId}`, payload);
}

/** `DELETE /accounting/dimensions/{id}/values/{value}` — refused while referenced. */
export function deleteFinancialDimensionValue(id: number, valueId: number): Promise<unknown> {
  return backendDelete(`accounting/dimensions/${id}/values/${valueId}`);
}

/** `POST /accounting/dimensions/{id}/values/{value}/activate` (no body). */
export function activateFinancialDimensionValue(id: number, valueId: number): Promise<unknown> {
  return backendPost(`accounting/dimensions/${id}/values/${valueId}/activate`);
}

/** `POST /accounting/dimensions/{id}/values/{value}/deactivate` (no body). */
export function deactivateFinancialDimensionValue(id: number, valueId: number): Promise<unknown> {
  return backendPost(`accounting/dimensions/${id}/values/${valueId}/deactivate`);
}
