"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchSalesInvoice } from "./api";
import type { SalesInvoiceDetailState } from "./types";
import { parseSalesInvoice } from "./validators";

const NO_COMPANY_REASON = "Select a company to see this invoice.";
const PERMISSION_REASON = "Your role does not include access to sales invoices.";

export type UseSalesInvoiceOptions = {
  invoiceId: number;
  companyId: number | null;
  enabled: boolean;
  reloadToken: number;
};

export function useSalesInvoice(options: UseSalesInvoiceOptions): SalesInvoiceDetailState {
  const { invoiceId, companyId, enabled, reloadToken } = options;
  const key = enabled && companyId !== null ? `${companyId}|${invoiceId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: SalesInvoiceDetailState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchSalesInvoice(invoiceId, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseSalesInvoice(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The invoice response was invalid." } });
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
            message: error instanceof ApiError ? error.message : "Could not load this invoice.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, invoiceId, reloadToken]);

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
