"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchCustomer } from "./api";
import type { CustomerDetailState } from "./types";
import { parseCustomer } from "./validators";

const NO_COMPANY_REASON = "Select a company to see this customer.";
const PERMISSION_REASON = "Your role does not include access to customers.";

export type UseCustomerOptions = {
  customerId: number;
  companyId: number | null;
  enabled: boolean;
  reloadToken: number;
};

/**
 * A single customer. A 404 becomes its own `notfound` state so the view renders
 * a real not-found boundary (cross-company ids resolve to 404, never 403).
 */
export function useCustomer(options: UseCustomerOptions): CustomerDetailState {
  const { customerId, companyId, enabled, reloadToken } = options;
  const key = enabled && companyId !== null ? `${companyId}|${customerId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: CustomerDetailState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchCustomer(customerId, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseCustomer(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The customer response was invalid." } });
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
          setStored({ key, data: { status: "unavailable", data: null, message: PERMISSION_REASON } });
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            data: null,
            message: error instanceof ApiError ? error.message : "Could not load this customer.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, customerId, reloadToken]);

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
