"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchTaxes } from "./api";
import type { TaxListFilters, TaxListState } from "./types";
import { parseTaxList, TaxesDataError } from "./validators";

/**
 * Tax list for the active company.
 *
 * The index endpoint returns the whole tax list in one response — it is not
 * paginated and its only filter is `active_only` — so this hook holds no page
 * state. It follows the list-hook conventions otherwise: one `AbortController`
 * per (company, filters, reloadToken) run with every state write guarded by
 * `signal.aborted`, and reads gated by `accounting.tax.view`.
 */

const NO_COMPANY_REASON = "Select a company to see its taxes.";
const PERMISSION_REASON = "Your role does not include access to taxes.";

export type UseTaxListOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: TaxListFilters;
  /** Bumped by the view after a write so the list re-reads authoritative data. */
  reloadToken: number;
};

export function useTaxList(options: UseTaxListOptions): TaxListState {
  const { companyId, enabled, filters, reloadToken } = options;
  const { active_only } = filters;

  const key = enabled ? `${companyId ?? ""}|${active_only ? "1" : "0"}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: TaxListState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchTaxes({ active_only }, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: parseTaxList(raw) });
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
              : "We could not load the taxes.";

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
