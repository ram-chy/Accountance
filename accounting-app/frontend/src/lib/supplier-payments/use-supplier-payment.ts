"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchSupplierPayment } from "./api";
import type { SupplierPaymentDetailState } from "./types";
import { parseSupplierPayment } from "./validators";

const NO_COMPANY_REASON = "Select a company to see this payment.";
const PERMISSION_REASON = "Your role does not include access to supplier payments.";

export type UseSupplierPaymentOptions = {
  paymentId: number;
  companyId: number | null;
  enabled: boolean;
  reloadToken: number;
};

export function useSupplierPayment(options: UseSupplierPaymentOptions): SupplierPaymentDetailState {
  const { paymentId, companyId, enabled, reloadToken } = options;
  const key = enabled && companyId !== null ? `${companyId}|${paymentId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: SupplierPaymentDetailState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchSupplierPayment(paymentId, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseSupplierPayment(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The payment response was invalid." } });
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
            message: error instanceof ApiError ? error.message : "Could not load this payment.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, paymentId, reloadToken]);

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
