"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type { PurchaseBillListQuery, PurchaseBillWritePayload } from "./types";

/**
 * Purchase bill backend calls through the same-origin proxy. `PUT` can never
 * post a bill and `POST /post` can never edit one, matching the backend's
 * structural draft/post split.
 */

export function fetchPurchaseBills(
  query: PurchaseBillListQuery,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    search: query.search || undefined,
    status: query.status || undefined,
    supplier_id: query.supplier_id,
    from: query.from || undefined,
    to: query.to || undefined,
    outstanding: query.outstanding,
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("purchase-bills", { query: params, signal });
}

export function fetchPurchaseBill(billId: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`purchase-bills/${billId}`, { signal });
}

export function createPurchaseBill(payload: PurchaseBillWritePayload): Promise<unknown> {
  return backendPost("purchase-bills", payload);
}

export function updatePurchaseBill(
  billId: number,
  payload: PurchaseBillWritePayload,
): Promise<unknown> {
  return backendPut(`purchase-bills/${billId}`, payload);
}

export function deletePurchaseBill(billId: number): Promise<unknown> {
  return backendDelete(`purchase-bills/${billId}`);
}

/** Post a draft. No body is accepted; a posted bill 409s. */
export function postPurchaseBill(billId: number): Promise<unknown> {
  return backendPost(`purchase-bills/${billId}/post`);
}
