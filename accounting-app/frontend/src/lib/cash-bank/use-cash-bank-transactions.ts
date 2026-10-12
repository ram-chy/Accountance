"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchCashBankTransactions } from "./api";
import type { CashBankTransactionFilters, CashBankTransactionListState } from "./types";
import { parseCashBankTransactionList } from "./validators";

/**
 * Cash/bank transaction list for the active company.
 *
 * Same shape as the journals list hook: one `AbortController` per
 * (company, filters, page) run, `signal.aborted`-guarded writes, and
 * render-derived loading / no-company / permission states so a company switch
 * is safe without a query cache. Gated by `accounting.cash_bank.view`.
 */

const NO_COMPANY_REASON = "Select a company to see its cash and bank transactions.";
const PERMISSION_REASON = "Your role does not include access to cash and bank transactions.";

/** The backend defaults to 25; matching it keeps one page per request modest. */
export const CASH_BANK_TRANSACTION_LIST_PER_PAGE = 25;

export type UseCashBankTransactionListOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: CashBankTransactionFilters;
  page: number;
  reloadToken: number;
};

/** Parse the account filter; a non-numeric value is treated as "all". */
export function parseAccountFilter(value: string): number | undefined {
  const trimmed = value.trim();

  if (trimmed === "") {
    return undefined;
  }

  const parsed = Number(trimmed);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : undefined;
}

export function useCashBankTransactionList(
  options: UseCashBankTransactionListOptions,
): CashBankTransactionListState {
  const { companyId, enabled, filters, page, reloadToken } = options;

  const transactionType = filters.transaction_type;
  const status = filters.status;
  const accountId = parseAccountFilter(filters.account_id);
  const from = filters.from;
  const to = filters.to;
  const reference = filters.reference.trim();

  const key = enabled
    ? runKey(companyId, transactionType, status, accountId, from, to, reference, page, reloadToken)
    : null;

  const [stored, setStored] = useState<{ key: string; data: CashBankTransactionListState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchCashBankTransactions(
      {
        transaction_type: transactionType || undefined,
        status: status || undefined,
        account_id: accountId,
        from: from || undefined,
        to: to || undefined,
        reference: reference || undefined,
        page,
        per_page: CASH_BANK_TRANSACTION_LIST_PER_PAGE,
      },
      signal,
    )
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({
          key,
          data: parseCashBankTransactionList(raw, CASH_BANK_TRANSACTION_LIST_PER_PAGE),
        });
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
              perPage: 0,
              hasMore: false,
              message: PERMISSION_REASON,
            },
          });
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            items: [],
            perPage: 0,
            hasMore: false,
            message:
              error instanceof ApiError
                ? error.message
                : "Could not load the cash and bank transactions. Please try again.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, transactionType, status, accountId, from, to, reference, page, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", items: [], perPage: 0, hasMore: false, message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "unavailable", items: [], perPage: 0, hasMore: false, message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", items: [], perPage: 0, hasMore: false };
}

function runKey(
  companyId: number | null,
  transactionType: string,
  status: string,
  accountId: number | undefined,
  from: string,
  to: string,
  reference: string,
  page: number,
  reloadToken: number,
): string {
  return [
    companyId ?? "",
    transactionType,
    status,
    accountId ?? "",
    from,
    to,
    reference,
    page,
    CASH_BANK_TRANSACTION_LIST_PER_PAGE,
    reloadToken,
  ].join("|");
}
