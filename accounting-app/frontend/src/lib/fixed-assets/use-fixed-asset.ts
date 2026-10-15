"use client";
import { useEffect, useState } from "react";
import { ApiError } from "@/lib/api/errors";
import { fetchFixedAsset } from "./api";
import type { FixedAssetDetailState } from "./types";
import { FixedAssetsDataError, parseFixedAssetDetail } from "./validators";

const PERMISSION_REASON = "Your role does not include access to fixed assets.";
const NO_COMPANY_REASON = "Select a company to see fixed assets.";

export type UseFixedAssetOptions = {
  id: number;
  companyId: number | null;
  enabled: boolean;
  reloadToken: number;
};

export function useFixedAsset(options: UseFixedAssetOptions): FixedAssetDetailState {
  const { id, companyId, enabled, reloadToken } = options;
  const key = enabled ? `${companyId}|${id}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: FixedAssetDetailState } | null>(null);
  useEffect(() => {
    if (companyId === null || key === null) return;
    const controller = new AbortController();
    const signal = controller.signal;
    fetchFixedAsset(id, signal)
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseFixedAssetDetail(raw) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        if (error instanceof ApiError && error.status === 404) {
          setStored({ key, data: { status: "notfound", asset: null } });
          return;
        }
        if (error instanceof ApiError && error.status === 403) {
          setStored({ key, data: { status: "unavailable", asset: null, message: PERMISSION_REASON } });
          return;
        }
        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof FixedAssetsDataError
              ? error.message
              : "Could not load fixed asset.";
        setStored({ key, data: { status: "error", asset: null, message, requestId: error instanceof ApiError ? error.requestId : undefined } });
      });
    return () => controller.abort();
  }, [key, companyId, id]);
  if (!enabled) return { status: "unavailable", asset: null, message: PERMISSION_REASON };
  if (companyId === null) return { status: "unavailable", asset: null, message: NO_COMPANY_REASON };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", asset: null };
}