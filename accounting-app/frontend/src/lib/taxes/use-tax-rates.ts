"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchTaxRates } from "./api";
import type { TaxRateListState } from "./types";
import { parseTaxRateList, TaxesDataError } from "./validators";

/**
 * A tax's rate history.
 *
 * Gated by `accounting.tax.view` (rates inherit the tax's view capability). One
 * `AbortController` per (company, tax, reloadToken) run; a 403 becomes
 * `unavailable`. The rate list is not paginated — a tax's history is short — so
 * the whole payload is narrowed in one pass.
 */

const PERMISSION_REASON = "Your role does not include access to tax rates.";

export type UseTaxRatesOptions = {
  taxId: number | null;
  companyId: number | null;
  enabled: boolean;
  /** Bumped by the view after a rate write. */
  reloadToken: number;
};

export function useTaxRates(options: UseTaxRatesOptions): TaxRateListState {
  const { taxId, companyId, enabled, reloadToken } = options;

  const key = enabled && taxId !== null ? `${companyId ?? ""}|${taxId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: TaxRateListState } | null>(null);

  useEffect(() => {
    if (companyId === null || taxId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchTaxRates(taxId, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: parseTaxRateList(raw) });
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
            : error instanceof TaxesDataError
              ? error.message
              : "We could not load the tax rates.";

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
  }, [key, companyId, taxId, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", items: [], message: PERMISSION_REASON };
  }

  if (companyId === null || taxId === null) {
    return { status: "unavailable", items: [], message: "Select a tax to see its rates." };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", items: [] };
}
