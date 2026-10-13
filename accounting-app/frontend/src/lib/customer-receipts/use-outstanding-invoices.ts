"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";
import { fetchSalesInvoices } from "@/lib/sales-invoices/api";
import type { SalesInvoice } from "@/lib/sales-invoices/types";
import { parseSalesInvoiceList } from "@/lib/sales-invoices/validators";

/**
 * The customer's outstanding (not fully settled) posted invoices, for the
 * allocation step. Uses the sales-invoice `outstanding` filter so the set and
 * each `balance_due` come from the backend; the browser never recomputes an
 * authoritative balance.
 */

export type OutstandingInvoicesState = {
  status: "idle" | "loading" | "ready" | "error";
  items: SalesInvoice[];
  message?: string;
};

const PAGE_SIZE = 100;

export function useOutstandingInvoices(
  companyId: number | null,
  customerId: number | null,
  enabled: boolean,
  reloadToken = 0,
): OutstandingInvoicesState {
  const key =
    enabled && companyId !== null && customerId !== null
      ? `${companyId}|${customerId}|${reloadToken}`
      : null;
  const [stored, setStored] = useState<{ key: string; data: OutstandingInvoicesState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null || customerId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchSalesInvoices(
      { customer_id: customerId, outstanding: true, per_page: PAGE_SIZE },
      signal,
    )
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseSalesInvoiceList(raw, PAGE_SIZE);
        if (parsed.status === "error") {
          setStored({ key, data: { status: "error", items: [], message: parsed.message } });
          return;
        }
        setStored({ key, data: { status: "ready", items: parsed.items } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        setStored({
          key,
          data: {
            status: "error",
            items: [],
            message:
              error instanceof ApiError
                ? error.message
                : "Could not load the customer's outstanding invoices.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, customerId]);

  if (!enabled || companyId === null || customerId === null) {
    return { status: "idle", items: [] };
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", items: [] };
}
