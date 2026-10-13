"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchPayables } from "./api";
import type { PayablesFilters, PayablesState } from "./payables-types";
import { parsePayables } from "./validators";

const PERMISSION_REASON = "Your role does not include access to reports.";
const NO_COMPANY_REASON = "Select a company to see its payables.";

export type UsePayablesOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: PayablesFilters;
  reloadToken: number;
};

/**
 * Outstanding payables for the active company as of `filters.as_of`.
 *
 * One `AbortController` per (company, filters, reload) run and every state write
 * guarded by `signal.aborted`, matching the other report hooks.
 */
export function usePayables(options: UsePayablesOptions): PayablesState {
  const { companyId, enabled, filters, reloadToken } = options;
  const { as_of, supplier_id } = filters;
  const key = enabled && companyId !== null
    ? `${companyId}|${as_of}|${supplier_id ?? ""}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: PayablesState } | null>(null);

  useEffect(() => {
    if (key === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchPayables({ as_of, supplier_id }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parsePayables(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The payables response was invalid." } });
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
            message: error instanceof ApiError ? error.message : "We could not load the payables report.",
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
