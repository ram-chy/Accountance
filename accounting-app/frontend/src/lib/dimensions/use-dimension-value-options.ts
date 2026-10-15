"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchFinancialDimensionValues } from "./api";
import type { FinancialDimensionValue } from "./types";
import { parseFinancialDimensionValueList } from "./validators";

const PAGE_SIZE = 100;
const MAX_PAGES = 20;

/** A compact value reference for a per-dimension picker. */
export type FinancialDimensionValueOption = {
  id: number;
  code: string;
  name: string;
  is_active: boolean;
};

export type FinancialDimensionValueOptionsState = {
  status: "loading" | "ready" | "error";
  items: FinancialDimensionValueOption[];
  message?: string;
};

function toOption(value: FinancialDimensionValue): FinancialDimensionValueOption {
  return { id: value.id, code: value.code, name: value.name, is_active: value.is_active };
}

function byCode(a: FinancialDimensionValueOption, b: FinancialDimensionValueOption): number {
  return a.code.localeCompare(b.code, undefined, { numeric: true });
}

/**
 * One dimension's values, for the select rendered beside it.
 *
 * A budget line carries at most one value per dimension, so a form that edits
 * several dimensions renders one of these per active dimension. Keeping this a
 * standalone hook — rather than a loop over fetches inside the parent — keeps
 * the hook calls per component instance and the abort semantics correct. All
 * values (active and inactive) are returned; the caller offers only the active
 * ones, matching the backend assignment rule.
 */
export function useFinancialDimensionValueOptions(
  dimensionId: number,
  enabled: boolean,
  reloadToken = 0,
): FinancialDimensionValueOptionsState {
  const key = enabled && dimensionId > 0 ? `${dimensionId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: FinancialDimensionValueOptionsState } | null>(null);

  useEffect(() => {
    if (key === null) return;
    const controller = new AbortController();
    const { signal } = controller;

    async function load(): Promise<FinancialDimensionValueOption[]> {
      const collected: FinancialDimensionValue[] = [];
      for (let page = 1; page <= MAX_PAGES; page += 1) {
        const raw = await fetchFinancialDimensionValues(dimensionId, { page, per_page: PAGE_SIZE }, signal);
        if (signal.aborted) return [];
        const list = parseFinancialDimensionValueList(raw, PAGE_SIZE);
        if (list.status !== "ready") break;
        collected.push(...list.items);
        if (!list.hasMore) break;
      }
      return collected.map(toOption).sort(byCode);
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
            message: error instanceof ApiError ? error.message : "Could not load dimension values.",
          },
        });
      });

    return () => controller.abort();
  }, [key, dimensionId]);

  if (!enabled) return { status: "error", items: [], message: "Dimension values are unavailable." };
  if (dimensionId <= 0) return { status: "error", items: [], message: "Select a dimension." };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", items: [] };
}
