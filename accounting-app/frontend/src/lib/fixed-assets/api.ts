"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type {
  DisposeFixedAssetPayload,
  FixedAssetCreateCashPayload,
  FixedAssetCreateSupplierCreditPayload,
  FixedAssetListFilters,
  FixedAssetUpdatePayload,
} from "./types";

/**
 * Fixed assets backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. The
 * browser never calls Laravel directly and never sends `company_id`.
 *
 * The acquisition method is carried by the route (`/cash` vs `/supplier-credit`)
 * because the verified `StoreFixedAssetRequest` does not accept an
 * `acquisition_method` field. `salvage_value` is nullable at the *domain* level
 * but the backend rule is a plain `decimal` (not `nullable`), so a null/blank
 * value is omitted rather than sent as `null` — the service then defaults it to
 * zero. All other nullable fields are accepted as `null` by the backend rules.
 */

/** Drop undefined values and an unset `salvage_value` so it defaults server-side. */
function toMoneyBody(payload: Record<string, unknown>): Record<string, unknown> {
  const body: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(payload)) {
    if (value === undefined) continue;
    if (key === "salvage_value" && (value === null || value === "")) continue;
    body[key] = value;
  }
  return body;
}

function toListParams(query: FixedAssetListFilters): QueryParams {
  return {
    status: query.status ?? undefined,
    acquisition_method: query.acquisition_method ?? undefined,
    fixed_asset_category_id: query.fixed_asset_category_id,
    from: query.from || undefined,
    to: query.to || undefined,
    search: query.search || undefined,
    per_page: query.per_page,
    page: query.page,
  };
}

/** `GET /accounting/fixed-assets` — all lifecycle states. */
export function fetchFixedAssets(
  query: FixedAssetListFilters,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet("accounting/fixed-assets", { query: toListParams(query), signal });
}

/** `GET /accounting/fixed-assets/register` — on-register assets only. */
export function fetchFixedAssetRegister(
  query: FixedAssetListFilters,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet("accounting/fixed-assets/register", { query: toListParams(query), signal });
}

/** `GET /accounting/fixed-assets/{id}` */
export function fetchFixedAsset(id: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`accounting/fixed-assets/${id}`, { signal });
}

/** `POST /accounting/fixed-assets/cash` (HTTP 201) */
export function createFixedAssetCash(payload: FixedAssetCreateCashPayload): Promise<unknown> {
  return backendPost("accounting/fixed-assets/cash", toMoneyBody(payload));
}

/** `POST /accounting/fixed-assets/supplier-credit` (HTTP 201) */
export function createFixedAssetSupplierCredit(
  payload: FixedAssetCreateSupplierCreditPayload,
): Promise<unknown> {
  return backendPost("accounting/fixed-assets/supplier-credit", toMoneyBody(payload));
}

/** `PUT /accounting/fixed-assets/{id}` — drafts only (409 otherwise). */
export function updateFixedAsset(
  id: number,
  payload: FixedAssetUpdatePayload,
): Promise<unknown> {
  return backendPut(`accounting/fixed-assets/${id}`, toMoneyBody(payload));
}

/** `DELETE /accounting/fixed-assets/{id}` — drafts only. */
export function deleteFixedAsset(id: number): Promise<unknown> {
  return backendDelete(`accounting/fixed-assets/${id}`);
}

/** `POST /accounting/fixed-assets/{id}/capitalize` — no body. */
export function capitalizeFixedAsset(id: number): Promise<unknown> {
  return backendPost(`accounting/fixed-assets/${id}/capitalize`);
}

/** `GET /accounting/fixed-assets/{id}/depreciation-schedule` */
export function fetchDepreciationSchedule(id: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`accounting/fixed-assets/${id}/depreciation-schedule`, { signal });
}

/** `POST /accounting/fixed-assets/{id}/depreciation` — optional `as_of`. */
export function depreciateFixedAsset(id: number, asOf?: string): Promise<unknown> {
  const body = asOf ? { as_of: asOf } : {};
  return backendPost(`accounting/fixed-assets/${id}/depreciation`, body);
}

/** `POST /accounting/fixed-assets/{id}/dispose` */
export function disposeFixedAsset(
  id: number,
  payload: DisposeFixedAssetPayload,
): Promise<unknown> {
  return backendPost(`accounting/fixed-assets/${id}/dispose`, toMoneyBody(payload));
}

/** `GET /accounting/fixed-assets/depreciation-report?from=&to=` */
export function fetchDepreciationReport(
  from: string,
  to: string,
  fixedAssetCategoryId?: number,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = { from, to, fixed_asset_category_id: fixedAssetCategoryId };
  return backendGet("accounting/fixed-assets/depreciation-report", { query: params, signal });
}
