"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type { SupplierPaymentListQuery, SupplierPaymentWritePayload } from "./types";

/** Supplier payment backend calls through the same-origin proxy. */

export function fetchSupplierPayments(
  query: SupplierPaymentListQuery,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    status: query.status || undefined,
    supplier_id: query.supplier_id,
    from: query.from || undefined,
    to: query.to || undefined,
    reference: query.reference || undefined,
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("supplier-payments", { query: params, signal });
}

export function fetchSupplierPayment(paymentId: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`supplier-payments/${paymentId}`, { signal });
}

export function createSupplierPayment(payload: SupplierPaymentWritePayload): Promise<unknown> {
  return backendPost("supplier-payments", payload);
}

export function updateSupplierPayment(
  paymentId: number,
  payload: SupplierPaymentWritePayload,
): Promise<unknown> {
  return backendPut(`supplier-payments/${paymentId}`, payload);
}

export function deleteSupplierPayment(paymentId: number): Promise<unknown> {
  return backendDelete(`supplier-payments/${paymentId}`);
}

/** Post a draft payment. No body is accepted; a posted payment 409s. */
export function postSupplierPayment(paymentId: number): Promise<unknown> {
  return backendPost(`supplier-payments/${paymentId}/post`);
}
