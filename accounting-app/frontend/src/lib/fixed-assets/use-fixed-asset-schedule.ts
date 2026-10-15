"use client";
import { useEffect, useState } from "react";
import { ApiError } from "@/lib/api/errors";
import { fetchDepreciationSchedule } from "./api";
import type { DepreciationScheduleResponse } from "./types";
import { FixedAssetsDataError, parseDepreciationScheduleResponse } from "./validators";

export type ScheduleState = { status: "loading" | "ready" | "error" | "unavailable" | "notfound"; data: DepreciationScheduleResponse | null; message?: string; requestId?: string };

export function useFixedAssetSchedule(id: number, companyId: number | null, enabled: boolean, reloadToken: number): ScheduleState {
  const key = enabled ? `${companyId}|${id}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: ScheduleState } | null>(null);
  useEffect(() => {
    if (companyId === null || key === null) return;
    const controller = new AbortController();
    const signal = controller.signal;
    fetchDepreciationSchedule(id, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseDepreciationScheduleResponse(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "Invalid schedule response." } });
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
          setStored({ key, data: { status: "unavailable", data: null, message: "Your role does not include access." } });
          return;
        }
        const message = error instanceof ApiError ? error.message : error instanceof FixedAssetsDataError ? error.message : "Could not load depreciation schedule.";
        setStored({ key, data: { status: "error", data: null, message, requestId: error instanceof ApiError ? error.requestId : undefined } });
      });
    return () => controller.abort();
  }, [key, id, companyId]);
  if (!enabled) return { status: "unavailable", data: null, message: "No access." };
  if (companyId === null) return { status: "unavailable", data: null, message: "Select a company." };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", data: null };
}