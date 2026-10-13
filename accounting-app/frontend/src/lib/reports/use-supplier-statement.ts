"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchSupplierStatement } from "./api";
import type { SupplierStatementFilters, SupplierStatementState } from "./supplier-statement-types";
import { parseSupplierStatement } from "./validators";

const PERMISSION_REASON = "Your role does not include access to reports.";
const NO_COMPANY_REASON = "Select a company to see its supplier statement.";

export type UseSupplierStatementOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: SupplierStatementFilters;
  reloadToken: number;
};

/**
 * Supplier account statement for the active company.
 *
 * `supplier_id` is required by the backend, so the hook stays `idle` until one
 * is chosen rather than firing a request that would 422. One `AbortController`
 * per (company, filters, reload) run and every state write guarded by
 * `signal.aborted`, matching the other report hooks.
 */
export function useSupplierStatement(options: UseSupplierStatementOptions): SupplierStatementState {
  const { companyId, enabled, filters, reloadToken } = options;
  const { supplier_id, from, to } = filters;
  const key = enabled && companyId !== null && supplier_id !== null
    ? `${companyId}|${supplier_id}|${from}|${to}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: SupplierStatementState } | null>(null);

  useEffect(() => {
    if (key === null || supplier_id === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchSupplierStatement({ supplier_id, from, to }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseSupplierStatement(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The supplier statement response was invalid." } });
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
            message: error instanceof ApiError ? error.message : "We could not load the supplier statement.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });
    return () => controller.abort();
  }, [key, supplier_id, from, to]);

  if (!enabled) {
    return { status: "unavailable", data: null, message: PERMISSION_REASON };
  }
  if (companyId === null) {
    return { status: "unavailable", data: null, message: NO_COMPANY_REASON };
  }
  if (supplier_id === null) {
    return { status: "idle", data: null };
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", data: null };
}
