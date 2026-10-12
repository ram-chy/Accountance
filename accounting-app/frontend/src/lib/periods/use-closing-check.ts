"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchPeriodClosingCheck } from "./api";
import type { ClosingCheck } from "./types";
import { parseClosingCheck, PeriodsDataError } from "./validators";

/**
 * The read-only period-end review for one period.
 *
 * Fetched when the review dialog opens (`enabled`) and re-fetched whenever the
 * view bumps `reloadToken` — after a close attempt is refused, for example, so
 * the report reflects the state the backend just judged rather than the snapshot
 * taken when the dialog opened. A single `AbortController` per period run means
 * closing the dialog or switching rows cannot land a stale report.
 */

export type ClosingCheckState =
  | { status: "idle" }
  | { status: "loading" }
  | { status: "ready"; data: ClosingCheck }
  | { status: "error"; message: string; requestId?: string };

export function useClosingCheck(
  periodId: number | null,
  enabled: boolean,
  reloadToken: number,
): ClosingCheckState {
  const key = enabled && periodId !== null ? `${periodId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: ClosingCheckState } | null>(null);

  useEffect(() => {
    if (key === null || periodId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchPeriodClosingCheck(periodId, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }

        const data = parseClosingCheck(raw);

        if (data === null) {
          setStored({
            key,
            data: { status: "error", message: "The closing review response was invalid." },
          });
          return;
        }

        setStored({ key, data: { status: "ready", data } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof PeriodsDataError
              ? error.message
              : "We could not evaluate the closing review.";

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
  }, [key, periodId]);

  if (!enabled || periodId === null) {
    return { status: "idle" };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading" };
}
