"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchTax } from "./api";
import type { TaxDetailState } from "./types";
import { parseTaxOrThrow, TaxesDataError } from "./validators";

/**
 * One tax for the active company.
 *
 * A 404 is its own state (`notfound`): a cross-company id is deliberately a 404
 * so it is indistinguishable from a tax that does not exist, and the view renders
 * the not-found boundary rather than a generic error. A 403 becomes `unavailable`.
 * Every state write is guarded by `signal.aborted`.
 */

const PERMISSION_REASON = "Your role does not include access to this tax.";
const NO_COMPANY_REASON = "Select a company to see this tax.";

export type UseTaxOptions = {
  taxId: number | null;
  companyId: number | null;
  enabled: boolean;
  /** Bumped by the view after a write so the tax re-reads authoritative data. */
  reloadToken: number;
};

export function useTax(options: UseTaxOptions): TaxDetailState {
  const { taxId, companyId, enabled, reloadToken } = options;

  const key = enabled && taxId !== null ? `${companyId ?? ""}|${taxId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: TaxDetailState } | null>(null);

  useEffect(() => {
    if (companyId === null || taxId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchTax(taxId, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }

        try {
          setStored({ key, data: { status: "ready", tax: parseTaxOrThrow(raw) } });
        } catch (error) {
          setStored({
            key,
            data: {
              status: "error",
              tax: null,
              message: error instanceof TaxesDataError ? error.message : "We could not read this tax.",
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
            data: { status: "unavailable", tax: null, message: PERMISSION_REASON },
          });
          return;
        }

        if (error instanceof ApiError && error.status === 404) {
          setStored({
            key,
            data: {
              status: "notfound",
              tax: null,
              message: "This tax could not be found.",
            },
          });
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            tax: null,
            message: error instanceof ApiError ? error.message : "We could not load this tax.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, taxId, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", tax: null, message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "unavailable", tax: null, message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", tax: null };
}
