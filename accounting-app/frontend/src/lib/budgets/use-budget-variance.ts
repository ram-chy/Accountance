"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchBudgetVariance } from "./api";
import type { BudgetVarianceFilters, BudgetVarianceState } from "./types";
import { BudgetsDataError, parseBudgetVarianceState } from "./validators";

const PERMISSION_REASON = "Your role does not include access to budgets.";
const NO_COMPANY_REASON = "Select a company to see its budget variance.";

export type UseBudgetVarianceOptions = {
  budgetId: number | null;
  companyId: number | null;
  enabled: boolean;
  filters: BudgetVarianceFilters;
  reloadToken: number;
};

/**
 * The variance report for one budget.
 *
 * Variance is defined per budget — there is no standalone variance endpoint — so
 * the request waits until a budget is selected. The actuals it measures against
 * are derived from posted journals on the server; nothing is computed here.
 * A missing budget is reported as `notfound` so the view can offer to pick
 * another, and a permission refusal as `unavailable`.
 */
export function useBudgetVariance(options: UseBudgetVarianceOptions): BudgetVarianceState {
  const { budgetId, companyId, enabled, filters, reloadToken } = options;
  const { dimension_id: dimensionId, dimension_value_id: dimensionValueId } = filters;
  const key =
    enabled && companyId !== null && budgetId !== null
      ? `${companyId}|${budgetId}|${dimensionId ?? "all"}|${dimensionValueId ?? "all"}|${reloadToken}`
      : null;
  const [stored, setStored] = useState<{ key: string; data: BudgetVarianceState } | null>(null);

  useEffect(() => {
    if (key === null || budgetId === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchBudgetVariance(budgetId, { dimension_id: dimensionId, dimension_value_id: dimensionValueId }, signal)
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseBudgetVarianceState(raw) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        if (error instanceof ApiError && error.status === 404) {
          setStored({ key, data: { status: "notfound", data: null } });
          return;
        }
        if (error instanceof ApiError && error.status === 403) {
          setStored({ key, data: { status: "unavailable", data: null, message: PERMISSION_REASON } });
          return;
        }
        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof BudgetsDataError
              ? error.message
              : "We could not load the budget variance.";
        setStored({
          key,
          data: {
            status: "error",
            data: null,
            message,
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });
    return () => controller.abort();
  }, [key, budgetId, dimensionId, dimensionValueId]);

  if (!enabled) return { status: "unavailable", data: null, message: PERMISSION_REASON };
  if (companyId === null) return { status: "unavailable", data: null, message: NO_COMPANY_REASON };
  if (budgetId === null) return { status: "loading", data: null };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", data: null };
}
