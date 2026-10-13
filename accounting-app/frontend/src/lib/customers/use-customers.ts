"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchCustomers } from "./api";
import type { CustomerListQuery, CustomerListState } from "./types";
import { parseCustomerList } from "./validators";

const NO_COMPANY_REASON = "Select a company to see its customers.";
const PERMISSION_REASON = "Your role does not include access to customers.";

export const CUSTOMER_LIST_PER_PAGE = 25;

export type UseCustomersOptions = {
  companyId: number | null;
  enabled: boolean;
  query: CustomerListQuery;
  reloadToken: number;
};

function runKey(companyId: number | null, query: CustomerListQuery, reloadToken: number): string {
  return [
    companyId ?? "",
    query.search ?? "",
    query.is_active === undefined ? "" : query.is_active ? "1" : "0",
    query.page ?? 1,
    query.per_page ?? CUSTOMER_LIST_PER_PAGE,
    reloadToken,
  ].join("|");
}

export function useCustomers(options: UseCustomersOptions): CustomerListState {
  const { companyId, enabled, query, reloadToken } = options;
  const key = enabled && companyId !== null ? runKey(companyId, query, reloadToken) : null;
  const [stored, setStored] = useState<{ key: string; data: CustomerListState } | null>(null);

  const { search, is_active, page, per_page } = query;

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchCustomers({ search, is_active, page, per_page }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseCustomerList(raw, per_page ?? CUSTOMER_LIST_PER_PAGE) });
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
            message: error instanceof ApiError ? error.message : "Could not load customers. Please try again.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, search, is_active, page, per_page]);

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
