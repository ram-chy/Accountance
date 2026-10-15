"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";
import { normalizeList } from "@/lib/api/pagination";

import { fetchCurrencies } from "./api";
import { parseCurrency } from "./validators";

/**
 * Active currencies, for the exchange-rate pair pickers.
 *
 * A rate can only reference an active currency (`ExchangeRateService`
 * refuses an inactive one), so the options are read with `active_only=1`. The
 * list is unpaginated by contract, so no page walking is needed.
 */

export type CurrencyOption = {
  id: number;
  code: string;
  name: string;
  symbol: string | null;
};

export type CurrencyOptionsState = {
  status: "loading" | "ready" | "error";
  items: CurrencyOption[];
  message?: string;
};

function byCode(a: CurrencyOption, b: CurrencyOption): number {
  return a.code.localeCompare(b.code);
}

export function useCurrencyOptions(
  companyId: number | null,
  enabled: boolean,
  reloadToken = 0,
): CurrencyOptionsState {
  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: CurrencyOptionsState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchCurrencies({ active_only: true }, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }

        const list = normalizeList<unknown>(raw);
        const items: CurrencyOption[] = [];

        for (const entry of list.items) {
          const currency = parseCurrency(entry);
          if (currency && currency.is_active) {
            items.push({
              id: currency.id,
              code: currency.code,
              name: currency.name,
              symbol: currency.symbol,
            });
          }
        }

        items.sort(byCode);
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
                : "Could not load currencies. Please try again.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId]);

  if (!enabled) {
    return { status: "error", items: [], message: "You do not have access to currencies." };
  }

  if (companyId === null) {
    return { status: "error", items: [], message: "Select a company to choose currencies." };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", items: [] };
}
