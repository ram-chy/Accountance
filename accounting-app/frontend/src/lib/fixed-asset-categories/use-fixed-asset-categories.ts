"use client";
import { useEffect, useState } from "react";
import { ApiError } from "@/lib/api/errors";
import { fetchFixedAssetCategories } from "./api";
import type { FixedAssetCategoryListFilters, FixedAssetCategoryListState } from "./types";
import { FixedAssetCategoriesDataError, parseFixedAssetCategoryList } from "./validators";

const PERMISSION_REASON = "Your role does not include access to fixed assets.";
const NO_COMPANY_REASON = "Select a company to see fixed asset categories.";

export const FIXED_ASSET_CATEGORIES_PER_PAGE = 25;

export type UseFixedAssetCategoriesOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: FixedAssetCategoryListFilters;
  page: number;
  perPage?: number;
  reloadToken: number;
};

export function useFixedAssetCategories(options: UseFixedAssetCategoriesOptions): FixedAssetCategoryListState {
  const { companyId, enabled, filters, page, perPage = FIXED_ASSET_CATEGORIES_PER_PAGE, reloadToken } = options;
  const isActive = filters.is_active;
  const search = filters.search ?? "";
  const key = enabled
    ? `${companyId}|${isActive ?? "all"}|${search}|${page}|${perPage}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: FixedAssetCategoryListState } | null>(null);
  useEffect(() => {
    if (companyId === null || key === null) return;
    const controller = new AbortController();
    const signal = controller.signal;
    fetchFixedAssetCategories({ is_active: isActive, search: search || undefined, page, per_page: perPage }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseFixedAssetCategoryList(raw, perPage) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        if (error instanceof ApiError && error.status === 403) {
          setStored({ key, data: { status: "unavailable", items: [], perPage, hasMore: false, message: PERMISSION_REASON } });
          return;
        }
        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof FixedAssetCategoriesDataError
              ? error.message
              : "Could not load fixed asset categories.";
        setStored({ key, data: { status: "error", items: [], perPage, hasMore: false, message, requestId: error instanceof ApiError ? error.requestId : undefined } });
      });
    return () => controller.abort();
  }, [key, companyId, isActive, search, page, perPage]);
  if (!enabled) return { status: "unavailable", items: [], perPage, hasMore: false, message: PERMISSION_REASON };
  if (companyId === null) return { status: "unavailable", items: [], perPage, hasMore: false, message: NO_COMPANY_REASON };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", items: [], perPage, hasMore: false };
}