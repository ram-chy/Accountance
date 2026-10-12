"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";
import { normalizeList } from "@/lib/api/pagination";

import { fetchFinancialYears } from "./api";
import type {
  FinancialYear,
  FinancialYearFilters,
  FinancialYearListState,
  FinancialYearOption,
  FinancialYearStatusValue,
} from "./types";
import { FinancialYearsDataError, parseFinancialYear, parseFinancialYearList } from "./validators";

/**
 * Financial-year list + selector data for the active company.
 *
 * The same shape as the account-list hook:
 *
 * - A single `AbortController` per (company, filters, page) run aborts the
 *   previous one on change, and every state write is guarded by `signal.aborted`
 *   so a slow response for Company A or an old status filter can never overwrite
 *   newer results.
 * - Loading / no-company / permission states are derived during render from a
 *   run key, never written synchronously inside the effect body.
 * - The page is gated by `accounting.periods.view` (financial years share the
 *   periods permission set); reads never fire without it.
 */

const NO_COMPANY_REASON = "Select a company to see its financial years.";
const PERMISSION_REASON = "Your role does not include access to financial years.";

export const FINANCIAL_YEAR_LIST_PER_PAGE = 50;

export type UseFinancialYearListOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: FinancialYearFilters;
  page: number;
  perPage?: number;
  /** Bumped by the view after a write so the list re-reads authoritative data. */
  reloadToken: number;
};

export function useFinancialYearList(
  options: UseFinancialYearListOptions,
): FinancialYearListState {
  const {
    companyId,
    enabled,
    filters,
    page,
    perPage = FINANCIAL_YEAR_LIST_PER_PAGE,
    reloadToken,
  } = options;

  const status: FinancialYearStatusValue | "" = filters.status;

  const key = enabled
    ? runKey(companyId, status, page, perPage, reloadToken)
    : null;

  const [stored, setStored] = useState<{ key: string; data: FinancialYearListState } | null>(
    null,
  );

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchFinancialYears(
      {
        status: status || undefined,
        page,
        per_page: perPage,
      },
      signal,
    )
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: parseFinancialYearList(raw, perPage) });
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
            : error instanceof FinancialYearsDataError
              ? error.message
              : "We could not load the financial years.";

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
  }, [key, companyId, status, page, perPage, reloadToken]);

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

export type FinancialYearOptionsState = {
  status: "loading" | "ready" | "error";
  items: FinancialYearOption[];
  message?: string;
};

const OPTIONS_PAGE_SIZE = 100;
const OPTIONS_MAX_PAGES = 20;

function toOption(year: FinancialYear): FinancialYearOption {
  return { id: year.id, name: year.name, status: year.status, start_date: year.start_date };
}

/** Newest first, so a picker prefers the year a user is most likely working in. */
function byStartDesc(a: FinancialYearOption, b: FinancialYearOption): number {
  return b.start_date.localeCompare(a.start_date) || b.id - a.id;
}

/**
 * The active company's financial years, for a picker.
 *
 * The endpoint returns a flat page with no `total`, so this walks the pages
 * until a short/empty page ends the sequence, with a defensive page cap so a
 * misbehaving backend cannot loop forever.
 */
export function useFinancialYearOptions(
  companyId: number | null,
  enabled: boolean,
  reloadToken = 0,
): FinancialYearOptionsState {
  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: FinancialYearOptionsState } | null>(
    null,
  );

  useEffect(() => {
    if (key === null || companyId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    async function load(): Promise<FinancialYearOption[]> {
      const collected: FinancialYear[] = [];

      for (let page = 1; page <= OPTIONS_MAX_PAGES; page += 1) {
        const raw = await fetchFinancialYears({ page, per_page: OPTIONS_PAGE_SIZE }, signal);

        if (signal.aborted) {
          return [];
        }

        const list = normalizeList<unknown>(raw, OPTIONS_PAGE_SIZE);

        for (const entry of list.items) {
          const year = parseFinancialYear(entry);
          if (year !== null) {
            collected.push(year);
          }
        }

        if (!list.hasMore) {
          break;
        }
      }

      return collected.map(toOption).sort(byStartDesc);
    }

    load()
      .then((items) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: { status: "ready", items } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            items: [],
            message:
              error instanceof ApiError
                ? error.message
                : "Could not load financial years. Please try again.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId]);

  if (!enabled) {
    return { status: "error", items: [], message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "error", items: [], message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", items: [] };
}

function runKey(
  companyId: number | null,
  status: FinancialYearStatusValue | "",
  page: number,
  perPage: number,
  reloadToken: number,
): string {
  return [companyId ?? "", status, page, perPage, reloadToken].join("|");
}
