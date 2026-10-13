"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchGeneralLedger } from "./api";
import type { GeneralLedgerFilters, GeneralLedgerState } from "./general-ledger-types";
import { parseGeneralLedger } from "./validators";

const PERMISSION_REASON = "Your role does not include access to reports.";
const NO_COMPANY_REASON = "Select a company to see its general ledger.";

export type UseGeneralLedgerOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: GeneralLedgerFilters;
  reloadToken: number;
};

export function useGeneralLedger(options: UseGeneralLedgerOptions): GeneralLedgerState {
  const { companyId, enabled, filters, reloadToken } = options;
  const { account_id: accountId, from, to } = filters;
  const key = enabled && companyId !== null && accountId !== null
    ? `${companyId}|${accountId}|${from}|${to}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: GeneralLedgerState } | null>(null);

  useEffect(() => {
    if (key === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchGeneralLedger({ account_id: accountId, from, to }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseGeneralLedger(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The general ledger response was invalid." } });
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
            message: error instanceof ApiError ? error.message : "We could not load the general ledger.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });
    return () => controller.abort();
  }, [key, companyId, accountId, from, to, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", data: null, message: PERMISSION_REASON };
  }
  if (companyId === null) {
    return { status: "unavailable", data: null, message: NO_COMPANY_REASON };
  }
  if (accountId === null) {
    return { status: "loading", data: null }; // wait for account selection
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", data: null };
}
