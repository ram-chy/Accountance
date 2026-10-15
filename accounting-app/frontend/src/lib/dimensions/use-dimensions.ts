"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchFinancialDimensions } from "./api";
import type { FinancialDimensionListFilters, FinancialDimensionListState } from "./types";
import { DimensionsDataError, parseFinancialDimensionList } from "./validators";

const PERMISSION_REASON = "Your role does not include access to dimensions.";
const NO_COMPANY_REASON = "Select a company to see its dimensions.";

export const FINANCIAL_DIMENSIONS_PER_PAGE = 25;

export type UseFinancialDimensionsOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: FinancialDimensionListFilters;
  page: number;
  perPage?: number;
  reloadToken: number;
};

/**
 * The active company's financial dimensions, paginated.
 *
 * One `AbortController` per (company, filters, page, reload) run aborts the
 * previous; every state write is guarded by `signal.aborted` so a slow response
 * for another company or filter can never overwrite newer results. Loading and
 * no-permission states are derived during render from a run key, never written
 * synchronously inside the effect.
 */
export function useFinancialDimensions(options: UseFinancialDimensionsOptions): FinancialDimensionListState {
  const {
    companyId,
    enabled,
    filters,
    page,
    perPage = FINANCIAL_DIMENSIONS_PER_PAGE,
    reloadToken,
  } = options;
  const { type, is_active: isActive, search } = filters;
  const trimmedSearch = search ?? "";
  const key = enabled
    ? `${companyId}|${type ?? "all"}|${isActive ?? "all"}|${trimmedSearch}|${page}|${perPage}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: FinancialDimensionListState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchFinancialDimensions(
      { type, is_active: isActive, search: trimmedSearch || undefined, page, per_page: perPage },
      signal,
    )
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseFinancialDimensionList(raw, perPage) });
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
            : error instanceof DimensionsDataError
              ? error.message
              : "Could not load dimensions.";
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
  }, [key, companyId, type, isActive, trimmedSearch, page, perPage]);

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
