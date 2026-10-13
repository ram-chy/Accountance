"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchCustomerReceipts } from "./api";
import type { CustomerReceiptListQuery, CustomerReceiptListState } from "./types";
import { parseCustomerReceiptList } from "./validators";

const NO_COMPANY_REASON = "Select a company to see its customer receipts.";
const PERMISSION_REASON = "Your role does not include access to customer receipts.";

export const CUSTOMER_RECEIPT_LIST_PER_PAGE = 25;

export type UseCustomerReceiptsOptions = {
  companyId: number | null;
  enabled: boolean;
  query: CustomerReceiptListQuery;
  reloadToken: number;
};

function runKey(companyId: number | null, query: CustomerReceiptListQuery, reloadToken: number): string {
  return [
    companyId ?? "",
    query.status ?? "",
    query.customer_id ?? "",
    query.from ?? "",
    query.to ?? "",
    query.reference ?? "",
    query.page ?? 1,
    query.per_page ?? CUSTOMER_RECEIPT_LIST_PER_PAGE,
    reloadToken,
  ].join("|");
}

export function useCustomerReceipts(options: UseCustomerReceiptsOptions): CustomerReceiptListState {
  const { companyId, enabled, query, reloadToken } = options;
  const key = enabled && companyId !== null ? runKey(companyId, query, reloadToken) : null;
  const [stored, setStored] = useState<{ key: string; data: CustomerReceiptListState } | null>(null);

  const { status, customer_id, from, to, reference, page, per_page } = query;

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchCustomerReceipts({ status, customer_id, from, to, reference, page, per_page }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseCustomerReceiptList(raw, per_page ?? CUSTOMER_RECEIPT_LIST_PER_PAGE) });
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
            message: error instanceof ApiError ? error.message : "Could not load customer receipts. Please try again.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, status, customer_id, from, to, reference, page, per_page]);

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
