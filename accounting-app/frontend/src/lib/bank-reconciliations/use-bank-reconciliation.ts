"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchBankReconciliation } from "./api";
import type { BankReconciliationDetailState } from "./types";
import { BankReconciliationsDataError, parseBankReconciliation } from "./validators";

/**
 * One bank reconciliation, for the detail screen.
 *
 * A 404 (an id from another company, a deleted reconciliation or a mistyped
 * path) becomes a distinct `not-found` state so the screen can offer a way back
 * to the list instead of a generic error. Every state write is guarded by
 * `signal.aborted`, so navigating between reconciliations cannot land a stale
 * record.
 */

export function useBankReconciliation(
  reconciliationId: number | null,
  enabled: boolean,
  reloadToken: number,
): BankReconciliationDetailState {
  const key =
    enabled && reconciliationId !== null ? `${reconciliationId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{
    key: string;
    data: BankReconciliationDetailState;
  } | null>(null);

  useEffect(() => {
    if (key === null || reconciliationId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchBankReconciliation(reconciliationId, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }

        const data = parseBankReconciliation(raw);

        if (data === null) {
          setStored({
            key,
            data: { status: "error", message: "The bank reconciliation response was invalid." },
          });
          return;
        }

        setStored({ key, data: { status: "ready", data } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        if (error instanceof ApiError && error.status === 404) {
          setStored({ key, data: { status: "not-found" } });
          return;
        }

        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof BankReconciliationsDataError
              ? error.message
              : "We could not load this bank reconciliation.";

        setStored({
          key,
          data: {
            status: "error",
            message,
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, reconciliationId]);

  if (!enabled || reconciliationId === null) {
    return { status: "idle" };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading" };
}
