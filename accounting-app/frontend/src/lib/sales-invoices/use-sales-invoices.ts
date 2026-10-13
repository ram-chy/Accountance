"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchSalesInvoices } from "./api";
import type { SalesInvoiceListQuery, SalesInvoiceListState } from "./types";
import { parseSalesInvoiceList } from "./validators";

const NO_COMPANY_REASON = "Select a company to see its sales invoices.";
const PERMISSION_REASON = "Your role does not include access to sales invoices.";

export const SALES_INVOICE_LIST_PER_PAGE = 25;

export type UseSalesInvoicesOptions = {
  companyId: number | null;
  enabled: boolean;
  query: SalesInvoiceListQuery;
  reloadToken: number;
};

function runKey(companyId: number | null, query: SalesInvoiceListQuery, reloadToken: number): string {
  return [
    companyId ?? "",
    query.search ?? "",
    query.status ?? "",
    query.customer_id ?? "",
    query.from ?? "",
    query.to ?? "",
    query.outstanding === undefined ? "" : query.outstanding ? "1" : "0",
    query.page ?? 1,
    query.per_page ?? SALES_INVOICE_LIST_PER_PAGE,
    reloadToken,
  ].join("|");
}

export function useSalesInvoices(options: UseSalesInvoicesOptions): SalesInvoiceListState {
  const { companyId, enabled, query, reloadToken } = options;
  const key = enabled && companyId !== null ? runKey(companyId, query, reloadToken) : null;
  const [stored, setStored] = useState<{ key: string; data: SalesInvoiceListState } | null>(null);

  const { search, status, customer_id, from, to, outstanding, page, per_page } = query;

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchSalesInvoices({ search, status, customer_id, from, to, outstanding, page, per_page }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseSalesInvoiceList(raw, per_page ?? SALES_INVOICE_LIST_PER_PAGE) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;

        if (error instanceof ApiError && error.status === 403) {
          setStored({
            key,
            data: { status: "unavailable", items: [], perPage: 0, hasMore: false, message: PERMISSION_REASON },
          });
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            items: [],
            perPage: 0,
            hasMore: false,
            message: error instanceof ApiError ? error.message : "Could not load sales invoices. Please try again.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, search, status, customer_id, from, to, outstanding, page, per_page]);

  if (!enabled) {
    return { status: "unavailable", items: [], perPage: 0, hasMore: false, message: PERMISSION_REASON };
  }
  if (companyId === null) {
    return { status: "unavailable", items: [], perPage: 0, hasMore: false, message: NO_COMPANY_REASON };
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", items: [], perPage: 0, hasMore: false };
}
