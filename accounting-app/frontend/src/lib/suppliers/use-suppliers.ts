"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchSuppliers } from "./api";
import type { SupplierListQuery, SupplierListState } from "./types";
import { parseSupplierList } from "./validators";

const NO_COMPANY_REASON = "Select a company to see its suppliers.";
const PERMISSION_REASON = "Your role does not include access to suppliers.";

export const SUPPLIER_LIST_PER_PAGE = 25;

export type UseSuppliersOptions = {
  companyId: number | null;
  enabled: boolean;
  query: SupplierListQuery;
  reloadToken: number;
};

function runKey(companyId: number | null, query: SupplierListQuery, reloadToken: number): string {
  return [
    companyId ?? "",
    query.search ?? "",
    query.is_active === undefined ? "" : query.is_active ? "1" : "0",
    query.page ?? 1,
    query.per_page ?? SUPPLIER_LIST_PER_PAGE,
    reloadToken,
  ].join("|");
}

export function useSuppliers(options: UseSuppliersOptions): SupplierListState {
  const { companyId, enabled, query, reloadToken } = options;
  const key = enabled && companyId !== null ? runKey(companyId, query, reloadToken) : null;
  const [stored, setStored] = useState<{ key: string; data: SupplierListState } | null>(null);

  const { search, is_active, page, per_page } = query;

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchSuppliers({ search, is_active, page, per_page }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseSupplierList(raw, per_page ?? SUPPLIER_LIST_PER_PAGE) });
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
            message: error instanceof ApiError ? error.message : "Could not load suppliers. Please try again.",
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
