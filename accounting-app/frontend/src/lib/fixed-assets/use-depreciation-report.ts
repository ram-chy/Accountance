"use client";
import { useEffect, useState } from "react";
import { ApiError } from "@/lib/api/errors";
import { fetchDepreciationReport } from "./api";
import type { DepreciationReport } from "./types";
import { FixedAssetsDataError, parseDepreciationReport } from "./validators";

export type ReportState = { status: "loading" | "ready" | "error" | "unavailable" | "notfound"; data: DepreciationReport | null; message?: string; requestId?: string };

export type UseDepreciationReportOptions = {
  companyId: number | null;
  enabled: boolean;
  from: string;
  to: string;
  fixed_asset_category_id?: number;
  reloadToken: number;
};

export function useDepreciationReport(options: UseDepreciationReportOptions): ReportState {
  const { companyId, enabled, from, to, fixed_asset_category_id, reloadToken } = options;
  const key = enabled ? `${companyId}|${from}|${to}|${fixed_asset_category_id ?? ""}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: ReportState } | null>(null);
  useEffect(() => {
    if (companyId === null || key === null) return;
    const controller = new AbortController();
    const signal = controller.signal;
    fetchDepreciationReport(from, to, fixed_asset_category_id, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseDepreciationReport(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "Invalid depreciation report." } });
          return;
        }
        setStored({ key, data: { status: "ready", data: parsed } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        if (error instanceof ApiError && error.status === 404) {
          setStored({ key, data: { status: "notfound", data: null } });
          return;
        }
        if (error instanceof ApiError && error.status === 403) {
          setStored({ key, data: { status: "unavailable", data: null, message: "No access to reports." } });
          return;
        }
        const message = error instanceof ApiError ? error.message : error instanceof FixedAssetsDataError ? error.message : "Could not load depreciation report.";
        setStored({ key, data: { status: "error", data: null, message, requestId: error instanceof ApiError ? error.requestId : undefined } });
      });
    return () => controller.abort();
  }, [key, companyId, from, to, fixed_asset_category_id]);
  if (!enabled) return { status: "unavailable", data: null, message: "No access to fixed assets." };
  if (companyId === null) return { status: "unavailable", data: null, message: "Select a company." };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", data: null };
}