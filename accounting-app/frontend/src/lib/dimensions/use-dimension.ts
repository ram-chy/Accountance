"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchFinancialDimension } from "./api";
import type { FinancialDimensionDetailState } from "./types";
import { DimensionsDataError, parseFinancialDimensionDetail } from "./validators";

const PERMISSION_REASON = "Your role does not include access to dimensions.";
const NO_COMPANY_REASON = "Select a company to see its dimensions.";

export type UseFinancialDimensionOptions = {
  id: number;
  companyId: number | null;
  enabled: boolean;
  reloadToken: number;
};

/** A single financial dimension, with its values loaded. */
export function useFinancialDimension(options: UseFinancialDimensionOptions): FinancialDimensionDetailState {
  const { id, companyId, enabled, reloadToken } = options;
  const key = enabled ? `${companyId}|${id}|${reloadToken}` : null;
  const [stored, setStored] = useState<{ key: string; data: FinancialDimensionDetailState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) return;
    const controller = new AbortController();
    const { signal } = controller;
    fetchFinancialDimension(id, signal)
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseFinancialDimensionDetail(raw) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        if (error instanceof ApiError && error.status === 404) {
          setStored({ key, data: { status: "notfound", dimension: null } });
          return;
        }
        if (error instanceof ApiError && error.status === 403) {
          setStored({ key, data: { status: "unavailable", dimension: null, message: PERMISSION_REASON } });
          return;
        }
        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof DimensionsDataError
              ? error.message
              : "Could not load this dimension.";
        setStored({
          key,
          data: { status: "error", dimension: null, message, requestId: error instanceof ApiError ? error.requestId : undefined },
        });
      });
    return () => controller.abort();
  }, [key, companyId, id]);

  if (!enabled) return { status: "unavailable", dimension: null, message: PERMISSION_REASON };
  if (companyId === null) return { status: "unavailable", dimension: null, message: NO_COMPANY_REASON };
  if (key !== null && stored && stored.key === key) return stored.data;
  return { status: "loading", dimension: null };
}
