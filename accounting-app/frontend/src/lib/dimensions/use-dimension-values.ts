"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchFinancialDimensionValues } from "./api";
import type {
  FinancialDimensionValueListFilters,
  FinancialDimensionValueListState,
} from "./types";
import { DimensionsDataError, parseFinancialDimensionValueList } from "./validators";

const PERMISSION_REASON = "Your role does not include access to dimensions.";
const NO_COMPANY_REASON = "Select a company to see dimension values.";

export const FINANCIAL_DIMENSION_VALUES_PER_PAGE = 25;

export type UseFinancialDimensionValuesOptions = {
  dimensionId: number;
  companyId: number | null;
  enabled: boolean;
  filters: FinancialDimensionValueListFilters;
  page: number;
  perPage?: number;
  reloadToken: number;
};

/** One dimension's values, paginated. */
export function useFinancialDimensionValues(
  options: UseFinancialDimensionValuesOptions,
): FinancialDimensionValueListState {
  const {
    dimensionId,
    companyId,
    enabled,
    filters,
    page,
    perPage = FINANCIAL_DIMENSION_VALUES_PER_PAGE,
    reloadToken,
  } = options;
  const { is_active: isActive, search } = filters;
  const trimmedSearch = search ?? "";
  const key = enabled
    ? `${companyId}|${dimensionId}|${isActive ?? "all"}|${trimmedSearch}|${page}|${perPage}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: FinancialDimensionValueListState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchFinancialDimensionValues(
      dimensionId,
      { is_active: isActive, search: trimmedSearch || undefined, page, per_page: perPage },
      signal,
    )
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseFinancialDimensionValueList(raw, perPage) });
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
              : "Could not load dimension values.";
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
  }, [key, companyId, dimensionId, isActive, trimmedSearch, page, perPage]);

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
