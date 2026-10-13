"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchPurchaseBills } from "./api";
import type { PurchaseBillListQuery, PurchaseBillListState } from "./types";
import { parsePurchaseBillList } from "./validators";

const NO_COMPANY_REASON = "Select a company to see its purchase bills.";
const PERMISSION_REASON = "Your role does not include access to purchase bills.";

export const PURCHASE_BILL_LIST_PER_PAGE = 25;

export type UsePurchaseBillsOptions = {
  companyId: number | null;
  enabled: boolean;
  query: PurchaseBillListQuery;
  reloadToken: number;
};

function runKey(companyId: number | null, query: PurchaseBillListQuery, reloadToken: number): string {
  return [
    companyId ?? "",
    query.search ?? "",
    query.status ?? "",
    query.supplier_id ?? "",
    query.from ?? "",
    query.to ?? "",
    query.outstanding === undefined ? "" : query.outstanding ? "1" : "0",
    query.page ?? 1,
    query.per_page ?? PURCHASE_BILL_LIST_PER_PAGE,
    reloadToken,
  ].join("|");
}

export function usePurchaseBills(options: UsePurchaseBillsOptions): PurchaseBillListState {
  const { companyId, enabled, query, reloadToken } = options;
  const key = enabled && companyId !== null ? runKey(companyId, query, reloadToken) : null;
  const [stored, setStored] = useState<{ key: string; data: PurchaseBillListState } | null>(null);

  const { search, status, supplier_id, from, to, outstanding, page, per_page } = query;

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchPurchaseBills({ search, status, supplier_id, from, to, outstanding, page, per_page }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parsePurchaseBillList(raw, per_page ?? PURCHASE_BILL_LIST_PER_PAGE) });
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
            message: error instanceof ApiError ? error.message : "Could not load purchase bills. Please try again.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, search, status, supplier_id, from, to, outstanding, page, per_page]);

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
