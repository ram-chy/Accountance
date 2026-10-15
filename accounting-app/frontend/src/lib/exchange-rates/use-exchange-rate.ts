"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchExchangeRate } from "./api";
import type { ExchangeRateDetailState } from "./types";
import { ExchangeRatesDataError, parseExchangeRateOrThrow } from "./validators";

/**
 * One exchange rate for the active company.
 *
 * A 404 is its own state (`notfound`): a cross-company id is deliberately a 404
 * so it is indistinguishable from a rate that does not exist, and the view
 * renders the not-found boundary rather than a generic error. A 403 becomes
 * `unavailable`. Every state write is guarded by `signal.aborted`.
 */

const PERMISSION_REASON = "Your role does not include access to exchange rates.";
const NO_COMPANY_REASON = "Select a company to see this exchange rate.";

export type UseExchangeRateOptions = {
  rateId: number | null;
  companyId: number | null;
  enabled: boolean;
  /** Bumped by the view after a write so the rate re-reads authoritative data. */
  reloadToken: number;
};

export function useExchangeRate(options: UseExchangeRateOptions): ExchangeRateDetailState {
  const { rateId, companyId, enabled, reloadToken } = options;

  const key = enabled && rateId !== null ? `${companyId ?? ""}|${rateId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: ExchangeRateDetailState } | null>(null);

  useEffect(() => {
    if (companyId === null || rateId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchExchangeRate(rateId, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }

        try {
          setStored({ key, data: { status: "ready", rate: parseExchangeRateOrThrow(raw) } });
        } catch (error) {
          setStored({
            key,
            data: {
              status: "error",
              rate: null,
              message:
                error instanceof ExchangeRatesDataError
                  ? error.message
                  : "We could not read this exchange rate.",
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
            data: { status: "unavailable", rate: null, message: PERMISSION_REASON },
          });
          return;
        }

        if (error instanceof ApiError && error.status === 404) {
          setStored({
            key,
            data: {
              status: "notfound",
              rate: null,
              message: "This exchange rate could not be found.",
            },
          });
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            rate: null,
            message:
              error instanceof ApiError ? error.message : "We could not load this exchange rate.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, rateId, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", rate: null, message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "unavailable", rate: null, message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", rate: null };
}
