"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchUsers } from "./api";
import type { UserListFilters, UserListState } from "./types";
import { UsersDataError, parseUserList } from "./validators";

/**
 * The global users roster.
 *
 * Users are not company-scoped, so unlike the accounting list hooks this one is
 * not gated on an active company — only on the `users.view` permission. It
 * follows the list-hook conventions: one `AbortController` per
 * (filters, reloadToken) run with every state write guarded by `signal.aborted`.
 *
 * A `403` becomes a calm "unavailable" state (the caller lacks `users.view`);
 * any other failure is a retryable error. A `401` is not handled here — the
 * shared unauthorized notifier in `backendFetch` already returns the user to
 * login.
 *
 * Search is passed in already debounced by the caller, so this hook stays
 * declarative and each key change is exactly one request.
 */

const PERMISSION_REASON = "Your role does not include access to user management.";

export type UseUserListOptions = {
  enabled: boolean;
  filters: UserListFilters;
  /** Bumped by the view after a write so the list re-reads authoritative data. */
  reloadToken: number;
};

export function useUserList(options: UseUserListOptions): UserListState {
  const { enabled, filters, reloadToken } = options;

  const search = filters.search?.trim() ?? "";
  const role = filters.role?.trim() ?? "";
  const isActive =
    filters.is_active === undefined ? "all" : filters.is_active ? "1" : "0";

  const key = enabled
    ? `${search}|${role}|${isActive}|${reloadToken}`
    : null;

  const [stored, setStored] = useState<{
    key: string;
    data: UserListState;
  } | null>(null);

  useEffect(() => {
    if (key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    // The tri-state filter is folded into the key, so the value is derived back
    // out of it here rather than reaching for the original object.
    const isActiveValue = isActive === "all" ? undefined : isActive === "1";

    fetchUsers({ search, role, is_active: isActiveValue }, signal)
      .then((raw) => {
        if (signal.aborted) {
          return;
        }
        setStored({ key, data: parseUserList(raw) });
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
              hasMore: false,
              message: PERMISSION_REASON,
            },
          });
          return;
        }

        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof UsersDataError
              ? error.message
              : "We could not load the users.";

        setStored({
          key,
          data: {
            status: "error",
            items: [],
            hasMore: false,
            message,
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, search, role, isActive]);

  if (!enabled) {
    return { status: "unavailable", items: [], hasMore: false, message: PERMISSION_REASON };
  }

  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }

  return { status: "loading", items: [], hasMore: false };
}
