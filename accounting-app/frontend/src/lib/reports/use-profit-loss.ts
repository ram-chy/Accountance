"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchProfitLoss } from "./api";
import type { ProfitLossFilters, ProfitLossState } from "./profit-loss-types";
import { parseProfitLoss } from "./validators";

const PERMISSION_REASON = "Your role does not include access to reports.";
const NO_COMPANY_REASON = "Select a company to see its profit & loss statement.";

export type UseProfitLossOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: ProfitLossFilters;
  reloadToken: number;
};

export function useProfitLoss(options: UseProfitLossOptions): ProfitLossState {
  const { companyId, enabled, filters, reloadToken } = options;
  const { from, to } = filters;
  const key = enabled && companyId !== null
    ? `${companyId}|${from}|${to}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: ProfitLossState } | null>(null);

  useEffect(() => {
    if (key === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchProfitLoss({ from, to }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseProfitLoss(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The profit & loss response was invalid." } });
          return;
        }
        setStored({ key, data: { status: "ready", data: parsed } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        if (error instanceof ApiError && error.status === 403) {
          setStored({ key, data: { status: "unavailable", data: null, message: PERMISSION_REASON } });
          return;
        }
        setStored({
          key,
          data: {
            status: "error",
            data: null,
            message: error instanceof ApiError ? error.message : "We could not load the profit & loss statement.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });
    return () => controller.abort();
  }, [key, companyId, from, to, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", data: null, message: PERMISSION_REASON };
  }
  if (companyId === null) {
    return { status: "unavailable", data: null, message: NO_COMPANY_REASON };
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", data: null };
}
