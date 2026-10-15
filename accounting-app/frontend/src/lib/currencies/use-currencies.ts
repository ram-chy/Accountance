"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchCurrencies } from "./api";
import type { CurrencyListFilters, CurrencyListState } from "./types";
import { CurrenciesDataError, parseCurrencyList } from "./validators";

/**
 * The currency master list.
 *
 * The index endpoint returns the whole list in one response — it is not
 * paginated and its only filter is `active_only` — so this hook holds no page
 * state. It follows the list-hook conventions: one `AbortController` per
 * (company, filters, reloadToken) run with every state write guarded by
 * `signal.aborted`, and reads gated by `accounting.currency.view`.
 *
 * Currencies are global, but the read still travels through the company-scoped
 * proxy, so the hook stays gated on an active company.
 */

const NO_COMPANY_REASON = "Select a company to see currencies.";
const PERMISSION_REASON = "Your role does not include access to currencies.";

export type UseCurrencyListOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: CurrencyListFilters;
  /** Bumped by the view after a write so the list re-reads authoritative data. */
  reloadToken: number;
};

export function useCurrencyList(options: UseCurrencyListOptions): CurrencyListState {
  const { companyId, enabled, filters, reloadToken } = options;
  const { active_only } = filters;

  const key = enabled ? `${companyId ?? ""}|${active_only ? "1" : "0"}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: CurrencyListState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchCurrencies({ active_only }, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: parseCurrencyList(raw) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        if (error instanceof ApiError && error.status === 403) {
          setStored({
            key,
            data: { status: "unavailable", items: [], message: PERMISSION_REASON },
          });
          return;
        }

        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof CurrenciesDataError
              ? error.message
              : "We could not load the currencies.";

        setStored({
          key,
          data: {
            status: "error",
            items: [],
            message,
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, active_only, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", items: [], message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "unavailable", items: [], message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", items: [] };
}
