"use client";

import { backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type { SupplierListQuery, SupplierWritePayload } from "./types";

/**
 * Supplier backend calls, all through the same-origin proxy. No call sends a
 * `company_id`; the scope comes from the server-held company context.
 */

export function fetchSuppliers(query: SupplierListQuery, signal?: AbortSignal): Promise<unknown> {
  const params: QueryParams = {
    search: query.search || undefined,
    is_active: query.is_active,
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("suppliers", { query: params, signal });
}

export function fetchSupplier(supplierId: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`suppliers/${supplierId}`, { signal });
}

export function createSupplier(payload: SupplierWritePayload): Promise<unknown> {
  return backendPost("suppliers", payload);
}

export function updateSupplier(supplierId: number, payload: SupplierWritePayload): Promise<unknown> {
  return backendPut(`suppliers/${supplierId}`, payload);
}

/**
 * Deactivation only — the backend has no supplier delete or reactivate.
 *
 * Reactivation would share the `suppliers.deactivate` permission, but the
 * backend exposes no route for it, so the UI offers deactivation alone.
 */
export function deactivateSupplier(supplierId: number): Promise<unknown> {
  return backendPost(`suppliers/${supplierId}/deactivate`);
}
