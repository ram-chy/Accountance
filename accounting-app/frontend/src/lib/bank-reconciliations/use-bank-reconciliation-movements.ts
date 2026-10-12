"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchBankReconciliationMovements } from "./api";
import type { BankReconciliationMovementsState } from "./types";
import {
  BankReconciliationsDataError,
  parseBankReconciliationMovements,
} from "./validators";

/**
 * The movements eligible to be cleared into one reconciliation.
 *
 * Re-fetched whenever the detail screen bumps `reloadToken` — after clearing a
 * movement, removing one, completing or reopening — so the table reflects the
 * state the backend just judged rather than the snapshot taken on open. A
 * single `AbortController` per reconciliation run means navigating away cannot
 * land a stale list.
 */

export function useBankReconciliationMovements(
  reconciliationId: number | null,
  enabled: boolean,
  reloadToken: number,
): BankReconciliationMovementsState {
  const key =
    enabled && reconciliationId !== null ? `${reconciliationId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{
    key: string;
    data: BankReconciliationMovementsState;
  } | null>(null);

  useEffect(() => {
    if (key === null || reconciliationId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchBankReconciliationMovements(reconciliationId, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: parseBankReconciliationMovements(raw) });
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
              : "We could not load the movements.";

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
