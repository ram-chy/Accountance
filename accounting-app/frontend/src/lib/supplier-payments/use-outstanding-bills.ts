"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";
import { fetchPurchaseBills } from "@/lib/purchase-bills/api";
import type { PurchaseBill } from "@/lib/purchase-bills/types";
import { parsePurchaseBillList } from "@/lib/purchase-bills/validators";

/**
 * The supplier's outstanding (not fully settled) posted bills, for the
 * allocation step. Uses the purchase-bill `outstanding` filter so the set and
 * each `balance_due` come from the backend; the browser never recomputes an
 * authoritative balance.
 */

export type OutstandingBillsState = {
  status: "idle" | "loading" | "ready" | "error";
  items: PurchaseBill[];
  message?: string;
};

const PAGE_SIZE = 100;

export function useOutstandingBills(
  companyId: number | null,
  supplierId: number | null,
  enabled: boolean,
  reloadToken = 0,
): OutstandingBillsState {
  const key =
    enabled && companyId !== null && supplierId !== null
      ? `${companyId}|${supplierId}|${reloadToken}`
      : null;
  const [stored, setStored] = useState<{ key: string; data: OutstandingBillsState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null || supplierId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchPurchaseBills(
      { supplier_id: supplierId, outstanding: true, per_page: PAGE_SIZE },
      signal,
    )
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parsePurchaseBillList(raw, PAGE_SIZE);
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
                : "Could not load the supplier's outstanding bills.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, supplierId]);

  if (!enabled || companyId === null || supplierId === null) {
    return { status: "idle", items: [] };
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", items: [] };
}
