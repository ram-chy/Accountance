"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchCompanySettings } from "./api";
import type { CompanySettingsState } from "./types";
import { CompanyDataError, parseCompanySettings } from "./validators";

/**
 * The active company's settings.
 *
 * Settings are a single company-scoped resource (`GET /company/settings`), so
 * this hook holds one value rather than a list. It follows the module
 * conventions: one `AbortController` per (company, reloadToken) run with every
 * state write guarded by `signal.aborted`, and reads gated by
 * `companies.settings.view`.
 *
 * A `403` becomes a calm "unavailable" state (the caller lacks the permission);
 * any other failure is a retryable error. The view bumps `reloadToken` after a
 * successful save so it re-reads the authoritative row instead of trusting its
 * own optimistic copy.
 */

const NO_COMPANY_REASON = "Select a company to see its settings.";
const PERMISSION_REASON =
  "Your role does not include access to company settings.";

export type UseCompanySettingsOptions = {
  companyId: number | null;
  enabled: boolean;
  reloadToken: number;
};

export function useCompanySettings(
  options: UseCompanySettingsOptions,
): CompanySettingsState {
  const { companyId, enabled, reloadToken } = options;

  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{
    key: string;
    data: CompanySettingsState;
  } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchCompanySettings(signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }

        const settings = parseCompanySettings(raw);

        setStored({
          key,
          data:
            settings === null
              ? {
                  status: "error",
                  settings: null,
                  message: "The company settings response was invalid.",
                }
              : { status: "ready", settings },
        });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        if (error instanceof ApiError && error.status === 403) {
          setStored({
            key,
            data: { status: "unavailable", settings: null, message: PERMISSION_REASON },
          });
          return;
        }

        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof CompanyDataError
              ? error.message
              : "We could not load the company settings.";

        setStored({
          key,
          data: {
            status: "error",
            settings: null,
            message,
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId]);

  if (!enabled) {
    return { status: "unavailable", settings: null, message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "unavailable", settings: null, message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", settings: null };
}
