"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";
import { normalizeList } from "@/lib/api/pagination";

import { fetchCashBankAccounts } from "./api";
import type {
  CashBankAccount,
  CashBankAccountFilters,
  CashBankAccountListState,
  CashBankKindValue,
} from "./types";
import { CashBankDataError, parseCashBankAccount, parseCashBankAccountList } from "./validators";

/**
 * Cash/bank account list for the active company.
 *
 * Same shape as the accounts and journals list hooks:
 *
 * - One `AbortController` per (company, filters, reload) run aborts the previous
 *   one on change, and every state write is guarded by `signal.aborted`.
 * - Loading / no-company / permission states are derived during render from a run
 *   key, never written synchronously in the effect body.
 * - The list is gated by `accounting.cash_bank.view` via `enabled`; an
 *   unauthorised caller never triggers a request.
 */

const NO_COMPANY_REASON = "Select a company to see its cash and bank accounts.";
const PERMISSION_REASON = "Your role does not include access to cash and bank accounts.";

/**
 * The backend has no page-number control for this list and the number of
 * classified accounts is small, so a single generous page is requested. The
 * flat-array `hasMore` caveat still applies and is surfaced by the view.
 */
export const CASH_BANK_ACCOUNT_LIST_PER_PAGE = 100;

export type UseCashBankAccountListOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: CashBankAccountFilters;
  reloadToken: number;
};

export function useCashBankAccountList(
  options: UseCashBankAccountListOptions,
): CashBankAccountListState {
  const { companyId, enabled, filters, reloadToken } = options;
  const kind = filters.kind;

  const key = enabled
    ? runKey(companyId, kind, reloadToken)
    : null;

  const [stored, setStored] = useState<{ key: string; data: CashBankAccountListState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchCashBankAccounts(
      { cash_bank_kind: kind || undefined, per_page: CASH_BANK_ACCOUNT_LIST_PER_PAGE },
      signal,
    )
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: parseCashBankAccountList(raw, CASH_BANK_ACCOUNT_LIST_PER_PAGE) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        if (error instanceof ApiError && error.status === 403) {
          setStored({
            key,
            data: { status: "unavailable", items: [], perPage: 0, hasMore: false, message: PERMISSION_REASON },
          });
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            items: [],
            perPage: 0,
            hasMore: false,
            message:
              error instanceof ApiError
                ? error.message
                : "Could not load the cash and bank accounts. Please try again.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, kind, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", items: [], perPage: 0, hasMore: false, message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "unavailable", items: [], perPage: 0, hasMore: false, message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", items: [], perPage: 0, hasMore: false };
}

function runKey(companyId: number | null, kind: CashBankKindValue | "", reloadToken: number): string {
  return [companyId ?? "", kind, reloadToken].join("|");
}

/** A classified account, trimmed to what a `<select>` option needs. */
export type CashBankAccountOption = {
  id: number;
  code: string;
  name: string;
  kind: CashBankKindValue | null;
  is_active: boolean;
};

export type CashBankAccountOptionsState = {
  status: "loading" | "ready" | "error";
  items: CashBankAccountOption[];
  message?: string;
};

const OPTIONS_PAGE_SIZE = 100;
const OPTIONS_MAX_PAGES = 10;

function toOption(account: CashBankAccount): CashBankAccountOption {
  return {
    id: account.id,
    code: account.code,
    name: account.name,
    kind: account.cash_bank_kind,
    is_active: account.is_active,
  };
}

function byCode(a: CashBankAccountOption, b: CashBankAccountOption): number {
  return a.code.localeCompare(b.code, undefined, { numeric: true });
}

/**
 * The company's classified cash/bank accounts, for the movement account
 * pickers. Walks the pages until a short page ends the sequence with a
 * defensive cap, mirroring `useAccountOptions`.
 */
export function useCashBankAccountOptions(
  companyId: number | null,
  enabled: boolean,
  reloadToken = 0,
): CashBankAccountOptionsState {
  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: CashBankAccountOptionsState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    async function load(): Promise<CashBankAccountOption[]> {
      const collected: CashBankAccount[] = [];

      for (let page = 1; page <= OPTIONS_MAX_PAGES; page += 1) {
        const raw = await fetchCashBankAccounts({ per_page: OPTIONS_PAGE_SIZE }, signal);

        if (signal.aborted) {
          return [];
        }

        const list = normalizeList<unknown>(raw, OPTIONS_PAGE_SIZE);

        for (const entry of list.items) {
          const account = parseCashBankAccount(entry);
          if (account !== null) {
            collected.push(account);
          }
        }

        if (!list.hasMore) {
          break;
        }
      }

      return collected.map(toOption).sort(byCode);
    }

    load()
      .then((items) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: { status: "ready", items } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            items: [],
            message:
              error instanceof ApiError
                ? error.message
                : error instanceof CashBankDataError
                  ? error.message
                  : "Could not load the cash and bank accounts. Please try again.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId]);

  if (!enabled) {
    return { status: "error", items: [], message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "error", items: [], message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", items: [] };
}
