"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchExchangeRates } from "./api";
import type { ExchangeRateListFilters, ExchangeRateListState } from "./types";
import { ExchangeRatesDataError, parseExchangeRateList } from "./validators";

/**
 * Exchange-rate list for the active company.
 *
 * The index endpoint returns the whole list in one response — it is not
 * paginated; its filters are `from_currency_id`, `to_currency_id` and
 * `active_only`, and it is ordered `effective_date` desc, then id desc. The hook
 * therefore holds no page state and follows the list-hook conventions: one
 * `AbortController` per (company, filters, reloadToken) run with every state
 * write guarded by `signal.aborted`, and reads gated by
 * `accounting.exchange_rate.view`.
 */

const NO_COMPANY_REASON = "Select a company to see its exchange rates.";
const PERMISSION_REASON = "Your role does not include access to exchange rates.";

export type UseExchangeRateListOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: ExchangeRateListFilters;
  /** Bumped by the view after a write so the list re-reads authoritative data. */
  reloadToken: number;
};

export function useExchangeRateList(
  options: UseExchangeRateListOptions,
): ExchangeRateListState {
  const { companyId, enabled, filters, reloadToken } = options;
  const { from_currency_id, to_currency_id, active_only } = filters;

  const key = enabled
    ? [
        companyId ?? "",
        from_currency_id ?? "",
        to_currency_id ?? "",
        active_only ? "1" : "0",
        reloadToken,
      ].join("|")
    : null;

  const [stored, setStored] = useState<{ key: string; data: ExchangeRateListState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchExchangeRates({ from_currency_id, to_currency_id, active_only }, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: parseExchangeRateList(raw) });
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
            : error instanceof ExchangeRatesDataError
              ? error.message
              : "We could not load the exchange rates.";

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
  }, [key, companyId, from_currency_id, to_currency_id, active_only, reloadToken]);

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
