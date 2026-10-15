"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchFinancialDimensions } from "./api";
import type { FinancialDimension, FinancialDimensionTypeValue } from "./types";
import { parseFinancialDimensionList } from "./validators";

const PAGE_SIZE = 100;
const MAX_PAGES = 20;

/** A compact dimension reference for a picker or a label resolution. */
export type FinancialDimensionOption = {
  id: number;
  type: FinancialDimensionTypeValue;
  type_label: string;
  code: string;
  name: string;
  is_active: boolean;
};

export type FinancialDimensionOptionsState = {
  status: "loading" | "ready" | "error";
  items: FinancialDimensionOption[];
  message?: string;
};

function toOption(dimension: FinancialDimension): FinancialDimensionOption {
  return {
    id: dimension.id,
    type: dimension.type,
    type_label: dimension.type_label,
    code: dimension.code,
    name: dimension.name,
    is_active: dimension.is_active,
  };
}

/** Backend ordering is (type, code); keep every dimension but in a stable order. */
function byTypeThenCode(a: FinancialDimensionOption, b: FinancialDimensionOption): number {
  return a.type.localeCompare(b.type) || a.code.localeCompare(b.code, undefined, { numeric: true });
}

/**
 * The active company's dimensions, for a picker.
 *
 * The listing is paginated and carries no reliable `total` in the current
 * backend shape, so this walks pages until a short page ends the sequence, with
 * a defensive page cap so a misbehaving backend cannot loop forever. Both active
 * and inactive dimensions are returned; the caller decides whether to offer an
 * inactive one (a budget-line assignment may only use active dimensions).
 */
export function useFinancialDimensionOptions(
  companyId: number | null,
  enabled: boolean,
  reloadToken = 0,
): FinancialDimensionOptionsState {
  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: FinancialDimensionOptionsState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null) return;
    const controller = new AbortController();
    const { signal } = controller;

    async function load(): Promise<FinancialDimensionOption[]> {
      const collected: FinancialDimension[] = [];
      for (let page = 1; page <= MAX_PAGES; page += 1) {
        const raw = await fetchFinancialDimensions({ page, per_page: PAGE_SIZE }, signal);
        if (signal.aborted) return [];
        const list = parseFinancialDimensionList(raw, PAGE_SIZE);
        if (list.status !== "ready") break;
        collected.push(...list.items);
        if (!list.hasMore) break;
      }
      return collected.map(toOption).sort(byTypeThenCode);
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
            message: error instanceof ApiError ? error.message : "Could not load dimensions. Please try again.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId]);

  if (!enabled) return { status: "error", items: [], message: "You do not have access to dimensions." };
  if (companyId === null) return { status: "error", items: [], message: "Select a company." };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", items: [] };
}
