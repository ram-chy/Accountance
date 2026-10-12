"use client";

import { useEffect, useState } from "react";

import { fetchAccounts } from "@/lib/accounts/api";
import type { Account, AccountTypeValue } from "@/lib/accounts/types";
import { parseAccount } from "@/lib/accounts/validators";
import { ApiError } from "@/lib/api/errors";
import { normalizeList } from "@/lib/api/pagination";

/**
 * The company's accounts for the tax account-mapping pickers, keeping
 * `account_type`.
 *
 * The shared `useAccountOptions` hook deliberately drops `account_type`, but the
 * mapping form needs it to show which accounts are eligible on each side (output
 * tax is a liability; input tax is an asset). This walks the flat, total-less
 * accounts pages the same way — capped so a misbehaving backend cannot loop — and
 * returns the full classification for each account.
 */

export type TaxAccountOption = {
  id: number;
  code: string;
  name: string;
  account_type: AccountTypeValue;
  is_active: boolean;
};

export type TaxAccountOptionsState = {
  status: "loading" | "ready" | "error";
  items: TaxAccountOption[];
  message?: string;
};

const PAGE_SIZE = 100;
const MAX_PAGES = 20;

function toOption(account: Account): TaxAccountOption {
  return {
    id: account.id,
    code: account.code,
    name: account.name,
    account_type: account.account_type,
    is_active: account.is_active,
  };
}

function byCode(a: TaxAccountOption, b: TaxAccountOption): number {
  return a.code.localeCompare(b.code, undefined, { numeric: true });
}

export function useTaxAccountOptions(
  companyId: number | null,
  enabled: boolean,
  reloadToken = 0,
): TaxAccountOptionsState {
  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: TaxAccountOptionsState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    async function load(): Promise<TaxAccountOption[]> {
      const collected: Account[] = [];

      for (let page = 1; page <= MAX_PAGES; page += 1) {
        const raw = await fetchAccounts({ page, per_page: PAGE_SIZE }, signal);

        if (signal.aborted) {
          return [];
        }

        const list = normalizeList<unknown>(raw, PAGE_SIZE);

        for (const entry of list.items) {
          const account = parseAccount(entry);
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
                : "Could not load accounts. Please try again.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId]);

  if (!enabled) {
    return { status: "error", items: [], message: "You do not have access to accounts." };
  }

  if (companyId === null) {
    return { status: "error", items: [], message: "Select a company to choose accounts." };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", items: [] };
}
