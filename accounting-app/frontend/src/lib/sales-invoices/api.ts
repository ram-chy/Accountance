"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type { SalesInvoiceListQuery, SalesInvoiceWritePayload } from "./types";

/**
 * Sales invoice backend calls through the same-origin proxy. `PUT` can never
 * post an invoice and `POST /post` can never edit one, matching the backend's
 * structural draft/post split.
 */

export function fetchSalesInvoices(
  query: SalesInvoiceListQuery,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    search: query.search || undefined,
    status: query.status || undefined,
    customer_id: query.customer_id,
    from: query.from || undefined,
    to: query.to || undefined,
    outstanding: query.outstanding,
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("sales-invoices", { query: params, signal });
}

export function fetchSalesInvoice(invoiceId: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`sales-invoices/${invoiceId}`, { signal });
}

export function createSalesInvoice(payload: SalesInvoiceWritePayload): Promise<unknown> {
  return backendPost("sales-invoices", payload);
}

export function updateSalesInvoice(
  invoiceId: number,
  payload: SalesInvoiceWritePayload,
): Promise<unknown> {
  return backendPut(`sales-invoices/${invoiceId}`, payload);
}

export function deleteSalesInvoice(invoiceId: number): Promise<unknown> {
  return backendDelete(`sales-invoices/${invoiceId}`);
}

/** Post a draft. No body is accepted; a posted invoice 409s. */
export function postSalesInvoice(invoiceId: number): Promise<unknown> {
  return backendPost(`sales-invoices/${invoiceId}/post`);
}
