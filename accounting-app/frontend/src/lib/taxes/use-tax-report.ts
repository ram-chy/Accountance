"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchTaxByTaxReport, fetchTaxSummaryReport } from "./api";
import type { TaxRangeFilters, TaxReportState } from "./types";
import { parseTaxReport, taxReportErrorState } from "./validators";

/**
 * Both tax reports for one date window.
 *
 * The summary and by-tax endpoints are independent, so they are fetched together
 * and resolved as one state. Both are read-only and derived from posted documents'
 * own line snapshots, so a rate changed since cannot restate a past period.
 *
 * Filter dates are the inclusive `from`/`to` window (aliases of `from_date`/
 * `to_date` the backend also accepts); an empty bound means "unbounded on that
 * side". Gated by `accounting.tax.report.view`.
 */

const PERMISSION_REASON = "Your role does not include access to tax reports.";
const NO_COMPANY_REASON = "Select a company to see its tax reports.";

export type UseTaxReportOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: TaxRangeFilters;
  reloadToken: number;
};

export function useTaxReport(options: UseTaxReportOptions): TaxReportState {
  const { companyId, enabled, filters, reloadToken } = options;
  const { from, to } = filters;

  const key = enabled ? `${companyId ?? ""}|${from}|${to}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: TaxReportState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    Promise.all([
      fetchTaxSummaryReport({ from, to }, signal),
      fetchTaxByTaxReport({ from, to }, signal),
    ])
      .then(([summaryRaw, byTaxRaw]) => {
        if (signal.aborted) {
          return;
        }

        const summary = parseTaxReport(summaryRaw);
        const byTax = parseTaxReport(byTaxRaw);

        if (summary === null || byTax === null) {
          setStored({
            key,
            data: taxReportErrorState("The tax report response was invalid."),
          });
          return;
        }

        setStored({ key, data: { status: "ready", summary, byTax } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        if (error instanceof ApiError && error.status === 403) {
          setStored({
            key,
            data: { status: "unavailable", summary: null, byTax: null, message: PERMISSION_REASON },
          });
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            summary: null,
            byTax: null,
            message: error instanceof ApiError ? error.message : "We could not load the tax reports.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, from, to, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", summary: null, byTax: null, message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "unavailable", summary: null, byTax: null, message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", summary: null, byTax: null };
}
