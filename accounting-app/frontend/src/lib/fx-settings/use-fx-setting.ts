"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchFxSetting } from "./api";
import type { FxSettingState } from "./types";
import { FxSettingsDataError, parseCompanyFxSettingOrThrow } from "./validators";

/**
 * The active company's FX settings.
 *
 * A singleton, so there is no id and no not-found case: the row always exists
 * for a company. A 403 (the role lacks `accounting.fx.update`, which gates both
 * reading and writing the setting) becomes `unavailable`. Every state write is
 * guarded by `signal.aborted`.
 */

const PERMISSION_REASON = "Your role does not include access to FX settings.";
const NO_COMPANY_REASON = "Select a company to see its FX settings.";

export type UseFxSettingOptions = {
  companyId: number | null;
  enabled: boolean;
  /** Bumped by the view after a write so the setting re-reads authoritative data. */
  reloadToken: number;
};

export function useFxSetting(options: UseFxSettingOptions): FxSettingState {
  const { companyId, enabled, reloadToken } = options;

  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: FxSettingState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchFxSetting(signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }

        try {
          setStored({ key, data: { status: "ready", setting: parseCompanyFxSettingOrThrow(raw) } });
        } catch (error) {
          setStored({
            key,
            data: {
              status: "error",
              setting: null,
              message:
                error instanceof FxSettingsDataError
                  ? error.message
                  : "We could not read the FX settings.",
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
            data: { status: "unavailable", setting: null, message: PERMISSION_REASON },
          });
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            setting: null,
            message:
              error instanceof ApiError ? error.message : "We could not load the FX settings.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", setting: null, message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "unavailable", setting: null, message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", setting: null };
}
