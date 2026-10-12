"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchBankReconciliations } from "./api";
import type { BankReconciliationFilters, BankReconciliationListState } from "./types";
import { BankReconciliationsDataError, parseBankReconciliationList } from "./validators";

/**
 * Bank-reconciliation list for the active company.
 *
 * The same shape as the period and account list hooks: one `AbortController`
 * per (company, filters, page) run with every state write guarded by
 * `signal.aborted`, loading / no-company / permission states derived from a run
 * key, and reads gated by `accounting.bank_reconciliation.view`.
 */

const NO_COMPANY_REASON = "Select a company to see its bank reconciliations.";
const PERMISSION_REASON = "Your role does not include access to bank reconciliations.";

export const BANK_RECONCILIATION_LIST_PER_PAGE = 25;

export type UseBankReconciliationListOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: BankReconciliationFilters;
  page: number;
  perPage?: number;
  /** Bumped by the view after a write so the list re-reads authoritative data. */
  reloadToken: number;
};

export function useBankReconciliationList(
  options: UseBankReconciliationListOptions,
): BankReconciliationListState {
  const {
    companyId,
    enabled,
    filters,
    page,
    perPage = BANK_RECONCILIATION_LIST_PER_PAGE,
    reloadToken,
  } = options;

  const { status, from_date, to_date } = filters;

  const key = enabled
    ? runKey(companyId, status, from_date, to_date, page, perPage, reloadToken)
    : null;

  const [stored, setStored] = useState<{
    key: string;
    data: BankReconciliationListState;
  } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchBankReconciliations(
      {
        status,
        from_date,
        to_date,
        page,
        per_page: perPage,
      },
      signal,
    )
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: parseBankReconciliationList(raw, perPage) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        if (error instanceof ApiError && error.status === 403) {
          setStored({
            key,
            data: {
              status: "unavailable",
              items: [],
              perPage,
              hasMore: false,
              message: PERMISSION_REASON,
            },
          });
          return;
        }

        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof BankReconciliationsDataError
              ? error.message
              : "We could not load the bank reconciliations.";

        setStored({
          key,
          data: {
            status: "error",
            items: [],
            perPage,
            hasMore: false,
            message,
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, status, from_date, to_date, page, perPage, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", items: [], perPage, hasMore: false, message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "unavailable", items: [], perPage, hasMore: false, message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", items: [], perPage, hasMore: false };
}

function runKey(
  companyId: number | null,
  status: string,
  fromDate: string,
  toDate: string,
  page: number,
  perPage: number,
  reloadToken: number,
): string {
  return [companyId ?? "", status, fromDate, toDate, page, perPage, reloadToken].join("|");
}
