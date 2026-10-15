"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchBudgets } from "./api";
import type { Budget, BudgetStatusValue } from "./types";
import { parseBudgetList } from "./validators";

const PAGE_SIZE = 100;
const MAX_PAGES = 20;

/** A compact budget reference for a picker or a link label. */
export type BudgetOption = {
  id: number;
  code: string;
  name: string;
  version_number: number;
  status: BudgetStatusValue;
};

export type BudgetOptionsState = {
  status: "loading" | "ready" | "error";
  items: BudgetOption[];
  message?: string;
};

function toOption(budget: Budget): BudgetOption {
  return {
    id: budget.id,
    code: budget.code,
    name: budget.name,
    version_number: budget.version_number,
    status: budget.status,
  };
}

/**
 * Backend ordering is (code, version_number). The picker shows the newest
 * version of a code first so a report selector defaults to the current plan.
 */
function byCodeThenVersionDesc(a: BudgetOption, b: BudgetOption): number {
  return a.code.localeCompare(b.code, undefined, { numeric: true }) || b.version_number - a.version_number;
}

/**
 * The active company's budgets, for a picker.
 *
 * The listing is paginated and carries no reliable `total` in the current
 * backend shape, so this walks pages until a short page ends the sequence, with
 * a defensive page cap so a misbehaving backend cannot loop forever. Both draft
 * and approved budgets are returned; a variance report is available for either.
 */
export function useBudgetOptions(
  companyId: number | null,
  enabled: boolean,
  reloadToken = 0,
): BudgetOptionsState {
  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: BudgetOptionsState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null) return;
    const controller = new AbortController();
    const { signal } = controller;

    async function load(): Promise<BudgetOption[]> {
      const collected: Budget[] = [];
      for (let page = 1; page <= MAX_PAGES; page += 1) {
        const raw = await fetchBudgets({ page, per_page: PAGE_SIZE }, signal);
        if (signal.aborted) return [];
        const list = parseBudgetList(raw, PAGE_SIZE);
        if (list.status !== "ready") break;
        collected.push(...list.items);
        if (!list.hasMore) break;
      }
      return collected.map(toOption).sort(byCodeThenVersionDesc);
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
            message: error instanceof ApiError ? error.message : "Could not load budgets. Please try again.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId]);

  if (!enabled) return { status: "error", items: [], message: "You do not have access to budgets." };
  if (companyId === null) return { status: "error", items: [], message: "Select a company." };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", items: [] };
}
