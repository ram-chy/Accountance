"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchAccountingPeriods } from "./api";
import type { PeriodFilters, PeriodListState } from "./types";
import { parseAccountingPeriodList, PeriodsDataError } from "./validators";

/**
 * Accounting-period list for the active company.
 *
 * The same shape as the account-list hook: one `AbortController` per
 * (company, filters, page) run with every state write guarded by
 * `signal.aborted`, loading / no-company / permission states derived from a run
 * key, and reads gated by `accounting.periods.view`.
 */

const NO_COMPANY_REASON = "Select a company to see its accounting periods.";
const PERMISSION_REASON = "Your role does not include access to accounting periods.";

export const PERIOD_LIST_PER_PAGE = 50;

export type UsePeriodListOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: PeriodFilters;
  page: number;
  perPage?: number;
  /** Bumped by the view after a write so the list re-reads authoritative data. */
  reloadToken: number;
};

export function usePeriodList(options: UsePeriodListOptions): PeriodListState {
  const {
    companyId,
    enabled,
    filters,
    page,
    perPage = PERIOD_LIST_PER_PAGE,
    reloadToken,
  } = options;

  const { status, financial_year_id, from_date, to_date } = filters;

  const key = enabled
    ? runKey(companyId, status, financial_year_id, from_date, to_date, page, perPage, reloadToken)
    : null;

  const [stored, setStored] = useState<{ key: string; data: PeriodListState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchAccountingPeriods(
      {
        status: status || undefined,
        financial_year_id: financial_year_id ?? undefined,
        from_date: from_date || undefined,
        to_date: to_date || undefined,
        page,
        per_page: perPage,
      },
      signal,
    )
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: parseAccountingPeriodList(raw, perPage) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        if (error instanceof ApiError && error.status === 403) {
          setStored({
            key,
            data: { status: "unavailable", items: [], perPage, hasMore: false, message: PERMISSION_REASON },
          });
          return;
        }

        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof PeriodsDataError
              ? error.message
              : "We could not load the accounting periods.";

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
  }, [key, companyId, status, financial_year_id, from_date, to_date, page, perPage, reloadToken]);

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
  financialYearId: number | null,
  fromDate: string,
  toDate: string,
  page: number,
  perPage: number,
  reloadToken: number,
): string {
  return [
    companyId ?? "",
    status,
    financialYearId ?? "",
    fromDate,
    toDate,
    page,
    perPage,
    reloadToken,
  ].join("|");
}
