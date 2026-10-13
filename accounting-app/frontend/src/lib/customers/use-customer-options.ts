"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";
import { normalizeList } from "@/lib/api/pagination";

import { fetchCustomers } from "./api";
import type { Customer } from "./types";
import { parseCustomer } from "./validators";

/**
 * The company's customers, for a picker. Walks the pages until a short page
 * ends the sequence with a defensive cap, mirroring `useAccountOptions`.
 */

export type CustomerOption = {
  id: number;
  customer_code: string;
  name: string;
  is_active: boolean;
};

export type CustomerOptionsState = {
  status: "loading" | "ready" | "error";
  items: CustomerOption[];
  message?: string;
};

const PAGE_SIZE = 100;
const MAX_PAGES = 20;

function toOption(customer: Customer): CustomerOption {
  return {
    id: customer.id,
    customer_code: customer.customer_code,
    name: customer.name,
    is_active: customer.is_active,
  };
}

function byName(a: CustomerOption, b: CustomerOption): number {
  return a.name.localeCompare(b.name, undefined, { sensitivity: "base" });
}

export function useCustomerOptions(
  companyId: number | null,
  enabled: boolean,
  reloadToken = 0,
): CustomerOptionsState {
  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: CustomerOptionsState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    async function load(): Promise<CustomerOption[]> {
      const collected: Customer[] = [];

      for (let page = 1; page <= MAX_PAGES; page += 1) {
        const raw = await fetchCustomers({ page, per_page: PAGE_SIZE }, signal);

        if (signal.aborted) return [];

        const list = normalizeList<unknown>(raw, PAGE_SIZE);

        for (const entry of list.items) {
          const customer = parseCustomer(entry);
          if (customer !== null) {
            collected.push(customer);
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
            message: error instanceof ApiError ? error.message : "Could not load customers. Please try again.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId]);

  if (!enabled) {
    return { status: "error", items: [], message: "You do not have access to customers." };
  }
  if (companyId === null) {
    return { status: "error", items: [], message: "Select a company to choose customers." };
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", items: [] };
}
