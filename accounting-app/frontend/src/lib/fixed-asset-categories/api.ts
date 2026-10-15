"use client";
import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";
import type {
  FixedAssetCategoryCreatePayload,
  FixedAssetCategoryListFilters,
  FixedAssetCategoryUpdatePayload,
} from "./types";

export function fetchFixedAssetCategories(query: FixedAssetCategoryListFilters, signal?: AbortSignal): Promise<unknown> {
  const params: QueryParams = {
    is_active: query.is_active === undefined ? undefined : query.is_active ? "1" : "0",
    search: query.search || undefined,
    per_page: query.per_page,
    page: query.page,
  };
  return backendGet("accounting/fixed-asset-categories", { query: params, signal });
}
export function fetchFixedAssetCategory(id: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`accounting/fixed-asset-categories/${id}`, { signal });
}
export function createFixedAssetCategory(payload: FixedAssetCategoryCreatePayload): Promise<unknown> {
  return backendPost("accounting/fixed-asset-categories", payload);
}
export function updateFixedAssetCategory(id: number, payload: FixedAssetCategoryUpdatePayload): Promise<unknown> {
  return backendPut(`accounting/fixed-asset-categories/${id}`, payload);
}
export function deleteFixedAssetCategory(id: number): Promise<unknown> {
  return backendDelete(`accounting/fixed-asset-categories/${id}`);
}
export function activateFixedAssetCategory(id: number): Promise<unknown> {
  return backendPost(`accounting/fixed-asset-categories/${id}/activate`);
}
export function deactivateFixedAssetCategory(id: number): Promise<unknown> {
  return backendPost(`accounting/fixed-asset-categories/${id}/deactivate`);
}