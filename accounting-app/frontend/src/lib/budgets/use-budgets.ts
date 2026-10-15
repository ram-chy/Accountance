"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchBudgets } from "./api";
import type { BudgetListFilters, BudgetListState } from "./types";
import { BudgetsDataError, parseBudgetList } from "./validators";

const PERMISSION_REASON = "Your role does not include access to budgets.";
const NO_COMPANY_REASON = "Select a company to see its budgets.";

export const BUDGETS_PER_PAGE = 25;

export type UseBudgetsOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: BudgetListFilters;
  page: number;
  perPage?: number;
  reloadToken: number;
};

/** The active company's budgets, paginated. */
export function useBudgets(options: UseBudgetsOptions): BudgetListState {
  const { companyId, enabled, filters, page, perPage = BUDGETS_PER_PAGE, reloadToken } = options;
  const { financial_year_id: yearId, status, search } = filters;
  const trimmedSearch = search ?? "";
  const key = enabled
    ? `${companyId}|${yearId ?? "all"}|${status ?? "all"}|${trimmedSearch}|${page}|${perPage}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: BudgetListState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchBudgets(
      { financial_year_id: yearId, status, search: trimmedSearch || undefined, page, per_page: perPage },
      signal,
    )
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseBudgetList(raw, perPage) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        if (error instanceof ApiError && error.status === 403) {
          setStored({
            key,
            data: { status: "unavailable", items: [], perPage, hasMore: false, message: PERMISSION_REASON },
          });
          return;
        }
        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof BudgetsDataError
              ? error.message
              : "Could not load budgets.";
        setStored({
          key,
          data: {
            status: "error",
            items: [],
            perPage,
            hasMore: false,
            message,
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });
    return () => controller.abort();
  }, [key, companyId, yearId, status, trimmedSearch, page, perPage]);

  if (!enabled) {
    return { status: "unavailable", items: [], perPage, hasMore: false, message: PERMISSION_REASON };
  }
  if (companyId === null) {
    return { status: "unavailable", items: [], perPage, hasMore: false, message: NO_COMPANY_REASON };
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", items: [], perPage, hasMore: false };
}
