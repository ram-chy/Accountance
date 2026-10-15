"use client";

import { backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type {
  CurrencyCreatePayload,
  CurrencyListFilters,
  CurrencyUpdatePayload,
} from "./types";

/**
 * Currency backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. The
 * browser never calls Laravel directly. Currencies are global reference data,
 * so no request carries a company id.
 *
 * List responses are returned as `unknown` and narrowed by `validators.ts`;
 * write responses are narrowed by `parseCurrencyOrThrow` in the mutation layer.
 * `apiFetch` unwraps the `{ data }` envelope, so a collection resolves to a bare
 * array and a single resource to its object.
 */

export function fetchCurrencies(
  filters: CurrencyListFilters = {},
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    // The endpoint only distinguishes "active only" from "all"; there is no
    // inactive-only filter, so a false/absent value is simply omitted.
    active_only: filters.active_only ? "1" : undefined,
  };

  return backendGet("accounting/currencies", { query: params, signal });
}

export function fetchCurrency(id: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`accounting/currencies/${id}`, { signal });
}

export function createCurrency(payload: CurrencyCreatePayload): Promise<unknown> {
  return backendPost("accounting/currencies", payload);
}

export function updateCurrency(id: number, payload: CurrencyUpdatePayload): Promise<unknown> {
  return backendPut(`accounting/currencies/${id}`, payload);
}

export function activateCurrency(id: number): Promise<unknown> {
  return backendPost(`accounting/currencies/${id}/activate`);
}

export function deactivateCurrency(id: number): Promise<unknown> {
  return backendPost(`accounting/currencies/${id}/deactivate`);
}
