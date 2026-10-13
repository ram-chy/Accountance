"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchPayablesAging } from "./api";
import type { PayablesAgingFilters, PayablesAgingState } from "./payables-aging-types";
import { parsePayablesAging } from "./validators";

const PERMISSION_REASON = "Your role does not include access to reports.";
const NO_COMPANY_REASON = "Select a company to see its aged payables.";

export type UsePayablesAgingOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: PayablesAgingFilters;
  reloadToken: number;
};

/**
 * Aged payables for the active company as of `filters.as_of`.
 *
 * One `AbortController` per (company, filters, reload) run and every state write
 * guarded by `signal.aborted`, matching the other report hooks.
 */
export function usePayablesAging(options: UsePayablesAgingOptions): PayablesAgingState {
  const { companyId, enabled, filters, reloadToken } = options;
  const { as_of, supplier_id } = filters;
  const key = enabled && companyId !== null
    ? `${companyId}|${as_of}|${supplier_id ?? ""}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: PayablesAgingState } | null>(null);

  useEffect(() => {
    if (key === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchPayablesAging({ as_of, supplier_id }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parsePayablesAging(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The aged payables response was invalid." } });
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
            message: error instanceof ApiError ? error.message : "We could not load the aged payables report.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });
    return () => controller.abort();
  }, [key, as_of, supplier_id]);

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
