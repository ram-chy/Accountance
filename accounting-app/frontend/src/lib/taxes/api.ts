"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type {
  TaxAccountMappingPayload,
  TaxCalculationRequest,
  TaxCreatePayload,
  TaxRateCreatePayload,
  TaxRateUpdatePayload,
  TaxUpdatePayload,
} from "./types";

/**
 * Tax backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. The
 * browser never calls Laravel directly and never sends `company_id` — the scope
 * comes from the server-side company context, and a tax belonging to another
 * company resolves to 404 (or, for a named id in a calculation body, to a 422).
 *
 * Read responses are returned as `unknown` and narrowed by `validators.ts`; write
 * responses are narrowed by the mutation layer. Note that `apiFetch` unwraps the
 * `{ data }` envelope, so a single resource resolves to its object, a resource
 * collection to a bare array, and a delete to its `{ message }` body.
 */

/** List the active company's taxes. The only supported filter is `active_only`. */
export function fetchTaxes(
  query: { active_only?: boolean } = {},
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    active_only: query.active_only ? "1" : undefined,
  };

  return backendGet("accounting/taxes", { query: params, signal });
}

export function fetchTax(taxId: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`accounting/taxes/${taxId}`, { signal });
}

export function createTax(payload: TaxCreatePayload): Promise<unknown> {
  return backendPost("accounting/taxes", payload);
}

export function updateTax(taxId: number, payload: TaxUpdatePayload): Promise<unknown> {
  return backendPut(`accounting/taxes/${taxId}`, payload);
}

export function deleteTax(taxId: number): Promise<unknown> {
  return backendDelete(`accounting/taxes/${taxId}`);
}

/** Make a tax available for new documents again. */
export function activateTax(taxId: number): Promise<unknown> {
  return backendPost(`accounting/taxes/${taxId}/activate`);
}

/** Stop applying a tax to new documents without destroying it. */
export function deactivateTax(taxId: number): Promise<unknown> {
  return backendPost(`accounting/taxes/${taxId}/deactivate`);
}

/** A tax's rate history, newest period last. */
export function fetchTaxRates(taxId: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`accounting/taxes/${taxId}/rates`, { signal });
}

export function createTaxRate(taxId: number, payload: TaxRateCreatePayload): Promise<unknown> {
  return backendPost(`accounting/taxes/${taxId}/rates`, payload);
}

export function updateTaxRate(
  taxId: number,
  rateId: number,
  payload: TaxRateUpdatePayload,
): Promise<unknown> {
  return backendPut(`accounting/taxes/${taxId}/rates/${rateId}`, payload);
}

export function deleteTaxRate(taxId: number, rateId: number): Promise<unknown> {
  return backendDelete(`accounting/taxes/${taxId}/rates/${rateId}`);
}

export function activateTaxRate(taxId: number, rateId: number): Promise<unknown> {
  return backendPost(`accounting/taxes/${taxId}/rates/${rateId}/activate`);
}

export function deactivateTaxRate(taxId: number, rateId: number): Promise<unknown> {
  return backendPost(`accounting/taxes/${taxId}/rates/${rateId}/deactivate`);
}

/** Where a tax's money posts; always answers, even when the tax is unmapped. */
export function fetchTaxAccountMapping(taxId: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`accounting/taxes/${taxId}/account-mapping`, { signal });
}

/** PUT with full replacement semantics: omitted account ids are stored as null. */
export function updateTaxAccountMapping(
  taxId: number,
  payload: TaxAccountMappingPayload,
): Promise<unknown> {
  return backendPut(`accounting/taxes/${taxId}/account-mapping`, payload);
}

/**
 * Calculate tax on an amount. Persists nothing — the endpoint is read-only and
 * writes no document, journal or rate.
 */
export function calculateTax(payload: TaxCalculationRequest): Promise<unknown> {
  return backendPost("accounting/tax/calculate", payload);
}

/** `GET /accounting/tax-reports/summary` — collected, recovered and net tax. */
export function fetchTaxSummaryReport(
  query: { from?: string; to?: string } = {},
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    from: query.from || undefined,
    to: query.to || undefined,
  };

  return backendGet("accounting/tax-reports/summary", { query: params, signal });
}

/** `GET /accounting/tax-reports/by-tax` — the same figures, one row per tax. */
export function fetchTaxByTaxReport(
  query: { from?: string; to?: string } = {},
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    from: query.from || undefined,
    to: query.to || undefined,
  };

  return backendGet("accounting/tax-reports/by-tax", { query: params, signal });
}
