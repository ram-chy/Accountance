"use client";

import { backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type {
  ExchangeRateCreatePayload,
  ExchangeRateListFilters,
  ExchangeRateUpdatePayload,
} from "./types";

/**
 * Exchange-rate backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. Rates are
 * company-scoped, so no request carries a company id — the scope comes from the
 * server-side company context, and a rate belonging to another company resolves
 * to 404.
 *
 * List responses are returned as `unknown` and narrowed by `validators.ts`;
 * write responses are narrowed by the mutation layer. `apiFetch` unwraps the
 * `{ data }` envelope, so a collection resolves to a bare array.
 */

function idParam(value: number | undefined): string | undefined {
  return value === undefined ? undefined : String(value);
}

export function fetchExchangeRates(
  filters: ExchangeRateListFilters = {},
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    from_currency_id: idParam(filters.from_currency_id),
    to_currency_id: idParam(filters.to_currency_id),
    // The endpoint only distinguishes "active only" from "all"; there is no
    // inactive-only filter, so a false/absent value is simply omitted.
    active_only: filters.active_only ? "1" : undefined,
  };

  return backendGet("accounting/exchange-rates", { query: params, signal });
}

export function fetchExchangeRate(id: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`accounting/exchange-rates/${id}`, { signal });
}

export function createExchangeRate(payload: ExchangeRateCreatePayload): Promise<unknown> {
  return backendPost("accounting/exchange-rates", payload);
}

export function updateExchangeRate(id: number, payload: ExchangeRateUpdatePayload): Promise<unknown> {
  return backendPut(`accounting/exchange-rates/${id}`, payload);
}

export function activateExchangeRate(id: number): Promise<unknown> {
  return backendPost(`accounting/exchange-rates/${id}/activate`);
}

export function deactivateExchangeRate(id: number): Promise<unknown> {
  return backendPost(`accounting/exchange-rates/${id}/deactivate`);
}
