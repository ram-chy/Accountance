"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchCustomerStatement } from "./api";
import type { CustomerStatementFilters, CustomerStatementState } from "./customer-statement-types";
import { parseCustomerStatement } from "./validators";

const PERMISSION_REASON = "Your role does not include access to reports.";
const NO_COMPANY_REASON = "Select a company to see its customer statement.";

export type UseCustomerStatementOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: CustomerStatementFilters;
  reloadToken: number;
};

/**
 * Customer account statement for the active company.
 *
 * `customer_id` is required by the backend, so the hook stays `idle` until one
 * is chosen rather than firing a request that would 422. One `AbortController`
 * per (company, filters, reload) run and every state write guarded by
 * `signal.aborted`, matching the other report hooks.
 */
export function useCustomerStatement(options: UseCustomerStatementOptions): CustomerStatementState {
  const { companyId, enabled, filters, reloadToken } = options;
  const { customer_id, from, to } = filters;
  const key = enabled && companyId !== null && customer_id !== null
    ? `${companyId}|${customer_id}|${from}|${to}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: CustomerStatementState } | null>(null);

  useEffect(() => {
    if (key === null || customer_id === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchCustomerStatement({ customer_id, from, to }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseCustomerStatement(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The customer statement response was invalid." } });
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
            message: error instanceof ApiError ? error.message : "We could not load the customer statement.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });
    return () => controller.abort();
  }, [key, customer_id, from, to]);

  if (!enabled) {
    return { status: "unavailable", data: null, message: PERMISSION_REASON };
  }
  if (companyId === null) {
    return { status: "unavailable", data: null, message: NO_COMPANY_REASON };
  }
  if (customer_id === null) {
    return { status: "idle", data: null };
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", data: null };
}
