"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";
import { normalizeList } from "@/lib/api/pagination";

import { fetchAccountingPeriods } from "./api";
import type { AccountingPeriod, PeriodStatusValue } from "./types";
import { parseAccountingPeriod } from "./validators";

/**
 * A period selector scoped to a financial year.
 *
 * Budget lines may only plan into an accounting period of the budget's own
 * financial year, so the line form asks for that year's periods directly rather
 * than loading every period in the company. The listing is paginated with no
 * reliable `total` in the current backend shape, so this walks pages until a
 * short page ends the sequence, with a defensive page cap.
 *
 * This is a small additive reader over the already-verified
 * `GET /accounting/periods?financial_year_id=` endpoint; it does not change the
 * existing periods list hook or screen.
 */

const PAGE_SIZE = 100;
const MAX_PAGES = 20;

export type AccountingPeriodOption = {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  status: PeriodStatusValue;
  accepts_postings: boolean;
};

export type AccountingPeriodOptionsState = {
  status: "loading" | "ready" | "error";
  items: AccountingPeriodOption[];
  message?: string;
};

function toOption(period: AccountingPeriod): AccountingPeriodOption {
  return {
    id: period.id,
    name: period.name,
    start_date: period.start_date,
    end_date: period.end_date,
    status: period.status,
    accepts_postings: period.accepts_postings,
  };
}

/** Chronological, so a budget lists its periods in the order they are planned. */
function byStart(a: AccountingPeriodOption, b: AccountingPeriodOption): number {
  return a.start_date.localeCompare(b.start_date) || a.id - b.id;
}

export function useAccountingPeriodOptions(
  financialYearId: number | null,
  enabled: boolean,
  reloadToken = 0,
): AccountingPeriodOptionsState {
  const key = enabled && financialYearId !== null ? `${financialYearId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: AccountingPeriodOptionsState } | null>(null);

  useEffect(() => {
    if (key === null || financialYearId === null) return;
    const controller = new AbortController();
    const { signal } = controller;

    async function load(): Promise<AccountingPeriodOption[]> {
      const collected: AccountingPeriod[] = [];
      for (let page = 1; page <= MAX_PAGES; page += 1) {
        const raw = await fetchAccountingPeriods(
          { financial_year_id: financialYearId ?? undefined, page, per_page: PAGE_SIZE },
          signal,
        );
        if (signal.aborted) return [];

        const list = normalizeList<unknown>(raw, PAGE_SIZE);
        for (const entry of list.items) {
          const period = parseAccountingPeriod(entry);
          if (period !== null) {
            collected.push(period);
          }
        }

        if (!list.hasMore) break;
      }
      return collected.map(toOption).sort(byStart);
    }

    load()
      .then((items) => {
        if (signal.aborted) return;
        setStored({ key, data: { status: "ready", items } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        setStored({
          key,
          data: {
            status: "error",
            items: [],
            message: error instanceof ApiError ? error.message : "Could not load accounting periods.",
          },
        });
      });

    return () => controller.abort();
  }, [key, financialYearId]);

  if (!enabled) return { status: "error", items: [], message: "Accounting periods are unavailable." };
  if (financialYearId === null) return { status: "error", items: [], message: "Select a financial year." };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", items: [] };
}
