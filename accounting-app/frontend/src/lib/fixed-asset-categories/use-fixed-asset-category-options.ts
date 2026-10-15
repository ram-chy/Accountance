"use client";
import { useEffect, useState } from "react";
import { ApiError } from "@/lib/api/errors";
import { fetchFixedAssetCategories } from "./api";
import type { FixedAssetCategory } from "./types";
import { parseFixedAssetCategoryList } from "./validators";

const PAGE_SIZE = 100;
const MAX_PAGES = 20;

export type FixedAssetCategoryOption = { id: number; code: string; name: string; is_active: boolean; useful_life_months: number };
export type FixedAssetCategoryOptionsState = { status: "loading" | "ready" | "error"; items: FixedAssetCategoryOption[]; message?: string };

function toOption(c: FixedAssetCategory): FixedAssetCategoryOption {
  return { id: c.id, code: c.code, name: c.name, is_active: c.is_active, useful_life_months: c.useful_life_months };
}

export function useFixedAssetCategoryOptions(companyId: number | null, enabled: boolean, reloadToken = 0): FixedAssetCategoryOptionsState {
  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: FixedAssetCategoryOptionsState } | null>(null);
  useEffect(() => {
    if (key === null) return;
    const controller = new AbortController();
    const signal = controller.signal;
    (async () => {
      const collected: FixedAssetCategory[] = [];
      for (let page = 1; page <= MAX_PAGES; page++) {
        const raw = await fetchFixedAssetCategories({ page, per_page: PAGE_SIZE }, signal);
        if (signal.aborted) return;
        const list = parseFixedAssetCategoryList(raw, PAGE_SIZE);
        if (list.status !== "ready") break;
        collected.push(...list.items);
        if (!list.hasMore) break;
      }
      return collected.map(toOption).sort((a,b) => a.code.localeCompare(b.code));
    })()
      .then((items) => {
        if (signal.aborted || !items) return;
        setStored({ key, data: { status: "ready", items } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        setStored({ key, data: { status: "error", items: [], message: error instanceof ApiError ? error.message : "Could not load categories." } });
      });
    return () => controller.abort();
  }, [key]);
  if (!enabled) return { status: "error", items: [], message: "You do not have access." };
  if (companyId === null) return { status: "error", items: [], message: "Select a company." };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", items: [] };
}