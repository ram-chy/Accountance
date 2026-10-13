"use client";

import { backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type { CustomerListQuery, CustomerWritePayload } from "./types";

/**
 * Customer backend calls, all through the same-origin proxy. No call sends a
 * `company_id`; the scope comes from the server-held company context.
 */

export function fetchCustomers(query: CustomerListQuery, signal?: AbortSignal): Promise<unknown> {
  const params: QueryParams = {
    search: query.search || undefined,
    is_active: query.is_active,
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("customers", { query: params, signal });
}

export function fetchCustomer(customerId: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`customers/${customerId}`, { signal });
}

export function createCustomer(payload: CustomerWritePayload): Promise<unknown> {
  return backendPost("customers", payload);
}

export function updateCustomer(customerId: number, payload: CustomerWritePayload): Promise<unknown> {
  return backendPut(`customers/${customerId}`, payload);
}

/** Deactivation only — the backend has no customer delete or reactivate. */
export function deactivateCustomer(customerId: number): Promise<unknown> {
  return backendPost(`customers/${customerId}/deactivate`);
}
