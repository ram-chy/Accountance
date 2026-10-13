"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchCashBank } from "./api";
import type { CashBankFilters, CashBankState } from "./cash-bank-report-types";
import { parseCashBank } from "./validators";

const PERMISSION_REASON = "Your role does not include access to reports.";
const NO_COMPANY_REASON = "Select a company to see its cash and bank report.";

export type UseCashBankReportOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: CashBankFilters;
  reloadToken: number;
};

/**
 * Cash/bank ledger for one account.
 *
 * The response is the general-ledger shape, so this hook mirrors
 * `useGeneralLedger`: an account is required before a request is made, and the
 * "no account selected yet" case is a loading state rather than an error.
 */
export function useCashBankReport(options: UseCashBankReportOptions): CashBankState {
  const { companyId, enabled, filters, reloadToken } = options;
  const { account_id: accountId, from, to } = filters;
  const key = enabled && companyId !== null && accountId !== null
    ? `${companyId}|${accountId}|${from}|${to}|${reloadToken}`
    : null;
  const [stored, setStored] = useState<{ key: string; data: CashBankState } | null>(null);

  useEffect(() => {
    if (key === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchCashBank({ account_id: accountId, from, to }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        const parsed = parseCashBank(raw);
        if (parsed === null) {
          setStored({ key, data: { status: "error", data: null, message: "The cash and bank report response was invalid." } });
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
            message: error instanceof ApiError ? error.message : "We could not load the cash and bank report.",
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
