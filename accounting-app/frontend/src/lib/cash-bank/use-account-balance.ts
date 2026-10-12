"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchAccountBalance } from "./api";
import { parseAccountBalance } from "./validators";

/**
 * One account's ledger balance (`GET /accounting/accounts/{account}/balance`).
 *
 * The cash/bank account resource deliberately exposes no balance — the money an
 * account holds is a question about posted journal lines, answered by the ledger
 * read. That endpoint is gated on `accounting.ledger.view`, which is a separate
 * capability from `accounting.cash_bank.view`, so a caller without it receives a
 * 403 and the balance is shown as unavailable rather than as zero.
 *
 * This is a per-account hook so a list row can own its own balance state without
 * an array dependency: the overview renders one small cell component per
 * account, each of which calls this hook. Additions are made in parallel by the
 * browser and each unmount aborts its own request.
 */

export type AccountBalanceState =
  | { status: "loading" }
  | { status: "ready"; balance: string }
  | { status: "unavailable" }
  | { status: "error"; message: string };

export function useAccountBalance(
  companyId: number | null,
  accountId: number,
  enabled: boolean,
  reloadToken = 0,
): AccountBalanceState {
  const key = enabled && companyId !== null && accountId > 0
    ? `${companyId}|${accountId}|${reloadToken}`
    : null;

  const [stored, setStored] = useState<{ key: string; data: AccountBalanceState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchAccountBalance(accountId, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }

        const parsed = parseAccountBalance(raw);

        if (parsed === null) {
          setStored({ key, data: { status: "unavailable" } });
          return;
        }

        setStored({ key, data: { status: "ready", balance: parsed.balance } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        if (error instanceof ApiError && error.status === 403) {
          setStored({ key, data: { status: "unavailable" } });
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            message:
              error instanceof ApiError
                ? error.message
                : "The balance could not be loaded.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, accountId]);

  if (!enabled || companyId === null || accountId <= 0) {
    return { status: "unavailable" };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading" };
}
