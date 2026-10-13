"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type { CustomerReceiptListQuery, CustomerReceiptWritePayload } from "./types";

/** Customer receipt backend calls through the same-origin proxy. */

export function fetchCustomerReceipts(
  query: CustomerReceiptListQuery,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    status: query.status || undefined,
    customer_id: query.customer_id,
    from: query.from || undefined,
    to: query.to || undefined,
    reference: query.reference || undefined,
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("customer-receipts", { query: params, signal });
}

export function fetchCustomerReceipt(receiptId: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`customer-receipts/${receiptId}`, { signal });
}

export function createCustomerReceipt(payload: CustomerReceiptWritePayload): Promise<unknown> {
  return backendPost("customer-receipts", payload);
}

export function updateCustomerReceipt(
  receiptId: number,
  payload: CustomerReceiptWritePayload,
): Promise<unknown> {
  return backendPut(`customer-receipts/${receiptId}`, payload);
}

export function deleteCustomerReceipt(receiptId: number): Promise<unknown> {
  return backendDelete(`customer-receipts/${receiptId}`);
}

/** Post a draft receipt. No body is accepted; a posted receipt 409s. */
export function postCustomerReceipt(receiptId: number): Promise<unknown> {
  return backendPost(`customer-receipts/${receiptId}/post`);
}
