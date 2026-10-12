"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type {
  AccountTypeValue,
  AccountWritePayload,
} from "./types";

/**
 * Chart of accounts backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. The
 * browser never calls Laravel directly and never sends `company_id` — the
 * scope comes from the server-side company context.
 *
 * List responses are returned as `unknown` and narrowed by `validators.ts`;
 * write responses are narrowed by `parseAccount` in the mutation layer.
 */

export type AccountListQuery = {
  search?: string;
  account_type?: AccountTypeValue;
  is_active?: boolean;
  page?: number;
  per_page?: number;
};

export function fetchAccounts(
  query: AccountListQuery,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    search: query.search || undefined,
    account_type: query.account_type || undefined,
    is_active:
      query.is_active === undefined ? undefined : query.is_active ? "1" : "0",
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("accounts", { query: params, signal });
}

export function fetchAccountTypes(signal?: AbortSignal): Promise<unknown> {
  return backendGet("accounts/types", { signal });
}

export function createAccount(payload: AccountWritePayload): Promise<unknown> {
  return backendPost("accounts", payload);
}

export function updateAccount(
  accountId: number,
  payload: Partial<AccountWritePayload>,
): Promise<unknown> {
  return backendPut(`accounts/${accountId}`, payload);
}

export function deleteAccount(accountId: number): Promise<unknown> {
  return backendDelete(`accounts/${accountId}`);
}

export function activateAccount(accountId: number): Promise<unknown> {
  return backendPost(`accounts/${accountId}/activate`);
}

export function deactivateAccount(accountId: number): Promise<unknown> {
  return backendPost(`accounts/${accountId}/deactivate`);
}