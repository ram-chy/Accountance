"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchSupplier } from "./api";
import type { SupplierDetailState } from "./types";
import { parseSupplier } from "./validators";

const NO_COMPANY_REASON = "Select a company to see this supplier.";
const PERMISSION_REASON = "Your role does not include access to suppliers.";

export type UseSupplierOptions = {
  supplierId: number;
  companyId: number | null;
  enabled: boolean;
  reloadToken: number;
};

/**
 * A single supplier. A 404 becomes its own `notfound` state so the view renders
 * a real not-found boundary (cross-company ids resolve to 404, never 403).
 */
export function useSupplier(options: UseSupplierOptions): SupplierDetailState {
  const { supplierId, companyId, enabled, reloadToken } = options;
  const key = enabled && companyId !== null ? `${companyId}|${supplierId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: SupplierDetailState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchSupplier(supplierId, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseSupplier(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The supplier response was invalid." } });
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
            message: error instanceof ApiError ? error.message : "Could not load this supplier.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, supplierId, reloadToken]);

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
