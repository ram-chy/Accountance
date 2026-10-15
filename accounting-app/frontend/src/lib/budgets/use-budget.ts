"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchBudget } from "./api";
import type { BudgetDetailState } from "./types";
import { BudgetsDataError, parseBudgetDetail } from "./validators";

const PERMISSION_REASON = "Your role does not include access to budgets.";
const NO_COMPANY_REASON = "Select a company to see its budgets.";

export type UseBudgetOptions = {
  id: number;
  companyId: number | null;
  enabled: boolean;
  reloadToken: number;
};

/** A single budget, with its financial year and lines loaded. */
export function useBudget(options: UseBudgetOptions): BudgetDetailState {
  const { id, companyId, enabled, reloadToken } = options;
  const key = enabled ? `${companyId}|${id}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: BudgetDetailState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchBudget(id, signal)
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseBudgetDetail(raw) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        if (error instanceof ApiError && error.status === 404) {
          setStored({ key, data: { status: "notfound", budget: null } });
          return;
        }
        if (error instanceof ApiError && error.status === 403) {
          setStored({ key, data: { status: "unavailable", budget: null, message: PERMISSION_REASON } });
          return;
        }
        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof BudgetsDataError
              ? error.message
              : "Could not load this budget.";
        setStored({
          key,
          data: {
            status: "error",
            budget: null,
            message,
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });
    return () => controller.abort();
  }, [key, companyId, id]);

  if (!enabled) return { status: "unavailable", budget: null, message: PERMISSION_REASON };
  if (companyId === null) return { status: "unavailable", budget: null, message: NO_COMPANY_REASON };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", budget: null };
}
