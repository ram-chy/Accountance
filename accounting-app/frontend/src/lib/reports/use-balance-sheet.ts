"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchBalanceSheet } from "./api";
import type { BalanceSheetFilters, BalanceSheetState } from "./balance-sheet-types";
import { parseBalanceSheet } from "./validators";

const PERMISSION_REASON = "Your role does not include access to reports.";
const NO_COMPANY_REASON = "Select a company to see its balance sheet.";

export type UseBalanceSheetOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: BalanceSheetFilters;
  reloadToken: number;
};

/**
 * Balance sheet for the active company, as of the `to` filter.
 *
 * One `AbortController` per (company, filters, reload) run, every state write
 * guarded by `signal.aborted`, and loading / no-company / permission states
 * derived during render from a run key — never written synchronously in the
 * effect body. This mirrors the trial balance and P&L hooks.
 */
export function useBalanceSheet(options: UseBalanceSheetOptions): BalanceSheetState {
  const { companyId, enabled, filters, reloadToken } = options;
  const { from, to } = filters;
  const key = enabled && companyId !== null
    ? `${companyId}|${from}|${to}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: BalanceSheetState } | null>(null);

  useEffect(() => {
    if (key === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchBalanceSheet({ from, to }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseBalanceSheet(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The balance sheet response was invalid." } });
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
            message: error instanceof ApiError ? error.message : "We could not load the balance sheet.",
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
