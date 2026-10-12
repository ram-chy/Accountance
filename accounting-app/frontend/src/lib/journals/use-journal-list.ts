"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchJournals, type JournalListQuery } from "./api";
import type { JournalListFilters, JournalListState } from "./types";
import { parseJournalList } from "./validators";

/**
 * Journal list for the active company.
 *
 * Same shape as the dashboard and account-list hooks:
 *
 * - One `AbortController` per (company, filters, page) run aborts the previous
 *   one on change, and every state write is guarded by `signal.aborted`, so a
 *   slow response for Company A or an old search cannot overwrite newer results.
 * - Loading / no-company / permission states are derived during render from a run
 *   key, never written synchronously in the effect body.
 * - The list is gated by `journals.view` via `enabled`; an unauthorised caller
 *   never triggers a request.
 */

const NO_COMPANY_REASON = "Select a company to see its journals.";
const PERMISSION_REASON = "Your role does not include access to journals.";

/** The backend defaults to 25; matching it keeps one page per request modest. */
export const JOURNAL_LIST_PER_PAGE = 25;

export type UseJournalListOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: JournalListFilters;
  page: number;
  perPage?: number;
  /** Bumped by the view after a write so the list re-reads authoritative data. */
  reloadToken: number;
};

export function useJournalList(options: UseJournalListOptions): JournalListState {
  const { companyId, enabled, filters, page, perPage = JOURNAL_LIST_PER_PAGE, reloadToken } = options;

  const search = filters.search.trim();
  const status = filters.status;
  const from = filters.from;
  const to = filters.to;

  const key = enabled
    ? runKey(companyId, search, status, from, to, page, perPage, reloadToken)
    : null;

  const [stored, setStored] = useState<{ key: string; data: JournalListState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    const query: JournalListQuery = {
      search: search || undefined,
      status: status || undefined,
      from: from || undefined,
      to: to || undefined,
      page,
      per_page: perPage,
    };

    fetchJournals(query, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: parseJournalList(raw, perPage) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        const status403 = error instanceof ApiError && error.status === 403;

        if (status403) {
          setStored({
            key,
            data: { status: "unavailable", items: [], perPage, hasMore: false, message: PERMISSION_REASON },
          });
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            items: [],
            perPage,
            hasMore: false,
            message:
              error instanceof ApiError
                ? error.message
                : "Could not load journals. Please try again.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, search, status, from, to, page, perPage, reloadToken]);

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
  search: string,
  status: string,
  from: string,
  to: string,
  page: number,
  perPage: number,
  reloadToken: number,
): string {
  return [companyId ?? "", search, status, from, to, page, perPage, reloadToken].join("|");
}
