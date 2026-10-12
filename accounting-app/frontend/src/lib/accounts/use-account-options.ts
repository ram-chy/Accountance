"use client";

import { useEffect, useState } from "react";

import { fetchAccounts } from "@/lib/accounts/api";
import { parseAccount } from "@/lib/accounts/validators";
import type { Account } from "@/lib/accounts/types";
import { ApiError } from "@/lib/api/errors";
import { normalizeList } from "@/lib/api/pagination";

/**
 * The company's accounts, for an account picker.
 *
 * Promoted from `@/lib/journals` in Phase 7 so both the journal line editor and
 * the cash/bank offset-account picker share one implementation instead of the
 * cash/bank module importing another feature's hook. The behaviour is unchanged.
 *
 * The accounts endpoint returns a flat page with no `total` (Phase 0 finding
 * P2-1), so this walks the pages until a short/empty page ends the sequence, with
 * a defensive page cap so a misbehaving backend cannot loop forever. A native
 * `<select>` is then populated from the complete list, which is simpler and more
 * accessible than a hand-rolled combobox and matches the project's dependency
 * -light stance.
 */

export type AccountOption = {
  id: number;
  code: string;
  name: string;
  is_active: boolean;
};

export type AccountOptionsState = {
  status: "loading" | "ready" | "error";
  items: AccountOption[];
  message?: string;
};

const PAGE_SIZE = 100;
const MAX_PAGES = 20;

function toOption(account: Account): AccountOption {
  return {
    id: account.id,
    code: account.code,
    name: account.name,
    is_active: account.is_active,
  };
}

function byCode(a: AccountOption, b: AccountOption): number {
  return a.code.localeCompare(b.code, undefined, { numeric: true });
}

export function useAccountOptions(
  companyId: number | null,
  enabled: boolean,
  reloadToken = 0,
): AccountOptionsState {
  const key = enabled && companyId !== null ? `${companyId}|${reloadToken}` : null;

  const [stored, setStored] = useState<{ key: string; data: AccountOptionsState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    async function load(): Promise<AccountOption[]> {
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
