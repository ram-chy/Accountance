"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchFinancialDimensionValues, fetchFinancialDimensions } from "./api";
import type { FinancialDimension, FinancialDimensionTypeValue } from "./types";
import { parseFinancialDimensionList, parseFinancialDimensionValueList } from "./validators";

/**
 * A resolved analytical label: which dimension, and which value of it.
 *
 * A budget line carries only `{ dimension_id, value_id }`, so a screen that wants
 * to show "Cost Center: CC-001 Sales" instead of two numbers needs both halves
 * looked up. This hook builds that lookup once rather than issuing a request per
 * rendered line.
 */

const PAGE_SIZE = 100;
const MAX_PAGES = 20;

export type DimensionLabel = {
  dimensionId: number;
  dimensionCode: string;
  dimensionName: string;
  dimensionType: FinancialDimensionTypeValue;
  dimensionTypeLabel: string;
  valueId: number;
  valueCode: string;
  valueName: string;
};

export type DimensionLabelMapState = {
  status: "loading" | "ready" | "error";
  /** Keyed by `dimensionLabelKey(dimensionId, valueId)`. */
  labels: Map<string, DimensionLabel>;
  message?: string;
};

export function dimensionLabelKey(dimensionId: number, valueId: number): string {
  return `${dimensionId}:${valueId}`;
}

async function loadDimensions(signal: AbortSignal): Promise<FinancialDimension[]> {
  const collected: FinancialDimension[] = [];
  for (let page = 1; page <= MAX_PAGES; page += 1) {
    const raw = await fetchFinancialDimensions({ page, per_page: PAGE_SIZE }, signal);
    if (signal.aborted) return [];
    const list = parseFinancialDimensionList(raw, PAGE_SIZE);
    if (list.status !== "ready") break;
    collected.push(...list.items);
    if (!list.hasMore) break;
  }
  return collected;
}

/**
 * A lookup from `{dimension_id, value_id}` to its human label, for rendering a
 * line's analytical metadata.
 *
 * The dimension list is walked once, then each dimension's values are walked, so
 * the cost is one pass per dimension rather than one request per rendered label.
 * A value whose dimension is missing is simply absent from the map; the caller
 * falls back to showing the raw ids rather than inventing a label.
 */
export function useDimensionLabelMap(
  companyId: number | null,
  enabled: boolean,
  reloadToken = 0,
): DimensionLabelMapState {
  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: DimensionLabelMapState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null) return;
    const controller = new AbortController();
    const { signal } = controller;

    async function load(): Promise<Map<string, DimensionLabel>> {
      const labels = new Map<string, DimensionLabel>();
      const dimensions = await loadDimensions(signal);

      for (const dimension of dimensions) {
        for (let page = 1; page <= MAX_PAGES; page += 1) {
          const raw = await fetchFinancialDimensionValues(dimension.id, { page, per_page: PAGE_SIZE }, signal);
          if (signal.aborted) return labels;
          const list = parseFinancialDimensionValueList(raw, PAGE_SIZE);
          if (list.status !== "ready") break;
          for (const value of list.items) {
            labels.set(dimensionLabelKey(dimension.id, value.id), {
              dimensionId: dimension.id,
              dimensionCode: dimension.code,
              dimensionName: dimension.name,
              dimensionType: dimension.type,
              dimensionTypeLabel: dimension.type_label || dimension.name,
              valueId: value.id,
              valueCode: value.code,
              valueName: value.name,
            });
          }
          if (!list.hasMore) break;
        }
      }

      return labels;
    }

    load()
      .then((labels) => {
        if (signal.aborted) return;
        setStored({ key, data: { status: "ready", labels } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        setStored({
          key,
          data: {
            status: "error",
            labels: new Map(),
            message: error instanceof ApiError ? error.message : "Could not load dimension labels.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId]);

  if (!enabled) return { status: "error", labels: new Map(), message: "Dimensions are unavailable." };
  if (companyId === null) return { status: "error", labels: new Map(), message: "Select a company." };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", labels: new Map() };
}
