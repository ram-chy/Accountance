"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchAccounts, fetchAccountTypes } from "./api";
import type {
  AccountListFilters,
  AccountListState,
  AccountTypeMeta,
  AccountTypeValue,
} from "./types";
import {
  AccountsDataError,
  parseAccountList,
  parseAccountTypes,
} from "./validators";

/**
 * Account list + type reference data for the active company.
 *
 * The same shape as the dashboard data hook:
 *
 * - A single `AbortController` per (company, filters, page) run aborts the
 *   previous one on change, and every state write is guarded by `signal.aborted`
 *   so a slow response for Company A or an old search can never overwrite newer
 *   results. This is what makes a company switch safe without a query cache.
 * - Loading / no-company / permission states are derived during render from a
 *   run key, never written synchronously inside the effect body, so the latest
 *   selection is always reflected while the request is in flight.
 * - The account page is gated separately from writes: `enabled` is
 *   `accounts.view`, and create/edit/activation use their own permissions in the
 *   view. An unauthorised caller never triggers a request.
 */

const NO_COMPANY_REASON = "Select a company to see its chart of accounts.";
const PERMISSION_REASON = "Your role does not include access to the chart of accounts.";

export const ACCOUNT_LIST_PER_PAGE = 50;

export type UseAccountListOptions = {
  companyId: number | null;
  enabled: boolean;
  filters: AccountListFilters;
  page: number;
  perPage?: number;
  /** Bumped by the view after a write so the list re-reads authoritative data. */
  reloadToken: number;
};

export function useAccountList(options: UseAccountListOptions): AccountListState {
  const { companyId, enabled, filters, page, perPage = ACCOUNT_LIST_PER_PAGE, reloadToken } = options;

  const search = filters.search.trim();
  const accountType: AccountTypeValue | "" = filters.account_type;
  const activeFilter = filters.is_active;

  const key = enabled ? runKey(companyId, search, accountType, activeFilter, page, perPage, reloadToken) : null;

  const [stored, setStored] = useState<{ key: string; data: AccountListState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchAccounts(
      {
        search: search || undefined,
        account_type: accountType || undefined,
        is_active: activeFilter === "" ? undefined : activeFilter === "active",
        page,
        per_page: perPage,
      },
      signal,
    )
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: parseAccountList(raw, perPage) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        if (error instanceof ApiError && error.status === 403) {
          setStored({
            key,
            data: {
              status: "unavailable",
              items: [],
              perPage,
              hasMore: false,
              message: PERMISSION_REASON,
            },
          });
          return;
        }

        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof AccountsDataError
              ? error.message
              : "We could not load the chart of accounts.";

        setStored({
          key,
          data: {
            status: "error",
            items: [],
            perPage,
            hasMore: false,
            message,
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, search, accountType, activeFilter, page, perPage, reloadToken]);

  if (!enabled) {
    return { status: "unavailable", items: [], perPage, hasMore: false, message: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "unavailable", items: [], perPage, hasMore: false, message: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", items: [], perPage, hasMore: false };
}

export type AccountTypesState =
  | { status: "loading" }
  | { status: "ready"; data: AccountTypeMeta[] }
  | { status: "unavailable"; reason: string };

/**
 * Fetch the fixed account-type reference data (with each type's normal
 * balance). Stable per company and refetched when the company changes, so a
 * form never carries one company's types into another company's screen.
 */
export function useAccountTypes(
  companyId: number | null,
  enabled: boolean,
): AccountTypesState {
  const key = enabled ? `types|${companyId ?? ""}` : null;
  const [stored, setStored] = useState<{ key: string; data: AccountTypesState } | null>(null);

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchAccountTypes(signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: { status: "ready", data: parseAccountTypes(raw) } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) {
          return;
        }

        if (error instanceof ApiError && error.status === 403) {
          setStored({ key, data: { status: "unavailable", reason: PERMISSION_REASON } });
          return;
        }

        setStored({ key, data: { status: "unavailable", reason: "Account types could not be loaded." } });
      });

    return () => controller.abort();
  }, [key, companyId]);

  if (!enabled) {
    return { status: "unavailable", reason: PERMISSION_REASON };
  }

  if (companyId === null) {
    return { status: "unavailable", reason: NO_COMPANY_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading" };
}

function runKey(
  companyId: number | null,
  search: string,
  accountType: AccountTypeValue | "",
  activeFilter: string,
  page: number,
  perPage: number,
  reloadToken: number,
): string {
  return [companyId ?? "", search, accountType, activeFilter, page, perPage, reloadToken].join("|");
}