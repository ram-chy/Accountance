"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";
import { normalizeList } from "@/lib/api/pagination";

import { fetchSuppliers } from "./api";
import type { Supplier } from "./types";
import { parseSupplier } from "./validators";

/**
 * The company's suppliers, for a picker. Walks the pages until a short page
 * ends the sequence with a defensive cap, mirroring `useAccountOptions`.
 */

export type SupplierOption = {
  id: number;
  supplier_code: string;
  name: string;
  is_active: boolean;
};

export type SupplierOptionsState = {
  status: "loading" | "ready" | "error";
  items: SupplierOption[];
  message?: string;
};

const PAGE_SIZE = 100;
const MAX_PAGES = 20;

function toOption(supplier: Supplier): SupplierOption {
  return {
    id: supplier.id,
    supplier_code: supplier.supplier_code,
    name: supplier.name,
    is_active: supplier.is_active,
  };
}

function byName(a: SupplierOption, b: SupplierOption): number {
  return a.name.localeCompare(b.name, undefined, { sensitivity: "base" });
}

export function useSupplierOptions(
  companyId: number | null,
  enabled: boolean,
  reloadToken = 0,
): SupplierOptionsState {
  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: SupplierOptionsState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    async function load(): Promise<SupplierOption[]> {
      const collected: Supplier[] = [];

      for (let page = 1; page <= MAX_PAGES; page += 1) {
        const raw = await fetchSuppliers({ page, per_page: PAGE_SIZE }, signal);

        if (signal.aborted) return [];

        const list = normalizeList<unknown>(raw, PAGE_SIZE);

        for (const entry of list.items) {
          const supplier = parseSupplier(entry);
          if (supplier !== null) {
            collected.push(supplier);
          }
        }

        if (!list.hasMore) break;
      }

      return collected.map(toOption).sort(byName);
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
            message: error instanceof ApiError ? error.message : "Could not load suppliers. Please try again.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId]);

  if (!enabled) {
    return { status: "error", items: [], message: "You do not have access to suppliers." };
  }
  if (companyId === null) {
    return { status: "error", items: [], message: "Select a company to choose suppliers." };
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", items: [] };
}
