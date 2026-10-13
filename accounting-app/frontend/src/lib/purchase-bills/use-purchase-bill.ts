"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchPurchaseBill } from "./api";
import type { PurchaseBillDetailState } from "./types";
import { parsePurchaseBill } from "./validators";

const NO_COMPANY_REASON = "Select a company to see this bill.";
const PERMISSION_REASON = "Your role does not include access to purchase bills.";

export type UsePurchaseBillOptions = {
  billId: number;
  companyId: number | null;
  enabled: boolean;
  reloadToken: number;
};

export function usePurchaseBill(options: UsePurchaseBillOptions): PurchaseBillDetailState {
  const { billId, companyId, enabled, reloadToken } = options;
  const key = enabled && companyId !== null ? `${companyId}|${billId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: PurchaseBillDetailState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchPurchaseBill(billId, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parsePurchaseBill(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The bill response was invalid." } });
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
            message: error instanceof ApiError ? error.message : "Could not load this bill.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, billId, reloadToken]);

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
