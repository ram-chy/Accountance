"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchCurrency } from "./api";
import type { CurrencyDetailState } from "./types";
import { CurrenciesDataError, parseCurrencyOrThrow } from "./validators";

/**
 * One currency by id.
 *
 * Currencies are global, so there is no cross-company 404 to disambiguate; a
 * 404 simply means the id does not exist and becomes the `notfound` state. A
 * 403 becomes `unavailable`. Every state write is guarded by `signal.aborted`.
 */

const PERMISSION_REASON = "Your role does not include access to this currency.";
const NO_COMPANY_REASON = "Select a company to see this currency.";

export type UseCurrencyOptions = {
  currencyId: number | null;
  companyId: number | null;
  enabled: boolean;
  /** Bumped by the view after a write so the currency re-reads authoritative data. */
  reloadToken: number;
};

export function useCurrency(options: UseCurrencyOptions): CurrencyDetailState {
  const { currencyId, companyId, enabled, reloadToken } = options;

  const key =
    enabled && currencyId !== null ? `${companyId ?? ""}|${currencyId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: CurrencyDetailState } | null>(null);

  useEffect(() => {
    if (companyId === null || currencyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchCurrency(currencyId, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }

        try {
          setStored({ key, data: { status: "ready", currency: parseCurrencyOrThrow(raw) } });
        } catch (error) {
          setStored({
            key,
            data: {
              status: "error",
              currency: null,
              message:
                error instanceof CurrenciesDataError
                  ? error.message
                  : "We could not read this currency.",
            },
          });
        }
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        if (error instanceof ApiError && error.status === 403) {
          setStored({
            key,
            data: { status: "unavailable", currency: null, message: PERMISSION_REASON },
          });
          return;
        }

        if (error instanceof ApiError && error.status === 404) {
          setStored({
            key,
            data: {
              status: "notfound",
              currency: null,
              message: "This currency could not be found.",
            },
          });
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            currency: null,
            message: error instanceof ApiError ? error.message : "We could not load this currency.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, currencyId, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", currency: null, message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "unavailable", currency: null, message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", currency: null };
}
