"use client";
import { useEffect, useState } from "react";
import { ApiError } from "@/lib/api/errors";
import { fetchFixedAssets, fetchFixedAssetRegister } from "./api";
import type { FixedAssetListFilters, FixedAssetListState } from "./types";
import { FixedAssetsDataError, parseFixedAssetList } from "./validators";

const PERMISSION_REASON = "Your role does not include access to fixed assets.";
const NO_COMPANY_REASON = "Select a company to see fixed assets.";

export const FIXED_ASSETS_PER_PAGE = 25;

export type UseFixedAssetsOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: FixedAssetListFilters;
  page: number;
  perPage?: number;
  reloadToken: number;
  mode?: "index" | "register";
};

export function useFixedAssets(options: UseFixedAssetsOptions): FixedAssetListState {
  const { companyId, enabled, filters, page, perPage = FIXED_ASSETS_PER_PAGE, reloadToken, mode = "index" } = options;
  const key = enabled
    ? `${companyId}|${mode}|${JSON.stringify(filters)}|${page}|${perPage}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: FixedAssetListState } | null>(null);
  useEffect(() => {
    if (companyId === null || key === null) return;
    const controller = new AbortController();
    const signal = controller.signal;
    const q = { ...filters, page, per_page: perPage };
    const fn = mode === "register" ? fetchFixedAssetRegister : fetchFixedAssets;
    fn(q, signal)
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseFixedAssetList(raw, perPage) });
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
            : error instanceof FixedAssetsDataError
              ? error.message
              : "Could not load fixed assets.";
        setStored({ key, data: { status: "error", items: [], perPage, hasMore: false, message, requestId: error instanceof ApiError ? error.requestId : undefined } });
      });
    return () => controller.abort();
  }, [key, companyId, filters, page, perPage, mode]);
  if (!enabled) return { status: "unavailable", items: [], perPage, hasMore: false, message: PERMISSION_REASON };
  if (companyId === null) return { status: "unavailable", items: [], perPage, hasMore: false, message: NO_COMPANY_REASON };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", items: [], perPage, hasMore: false };
}