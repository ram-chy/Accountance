"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type {
  CashBankAccountClassifyPayload,
  CashBankKindValue,
  CashBankTransactionStatusValue,
  CashBankTransactionTypeValue,
  CashBankTransactionWritePayload,
} from "./types";

/**
 * Cash & bank backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. The
 * browser never calls Laravel directly and never sends `company_id` — the scope
 * comes from the server-side company context.
 *
 * The three movement types are three create endpoints rather than one endpoint
 * with a `type` field, because the backend makes the type a property of the
 * route (the eligibility rule depends on it). `createCashBankTransaction` picks
 * the endpoint from the chosen type.
 *
 * List responses are returned as `unknown` and narrowed by `validators.ts`.
 */

const TRANSACTION_ENDPOINT: Record<CashBankTransactionTypeValue, string> = {
  DEPOSIT: "deposits",
  WITHDRAWAL: "withdrawals",
  TRANSFER: "transfers",
};

export type CashBankAccountListQuery = {
  cash_bank_kind?: CashBankKindValue;
  per_page?: number;
};

export function fetchCashBankAccounts(
  query: CashBankAccountListQuery = {},
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    cash_bank_kind: query.cash_bank_kind || undefined,
    per_page: query.per_page,
  };

  return backendGet("cash-bank-accounts", { query: params, signal });
}

export function updateCashBankAccount(
  accountId: number,
  payload: CashBankAccountClassifyPayload,
): Promise<unknown> {
  return backendPut(`cash-bank-accounts/${accountId}`, payload);
}

export function deleteBankDetails(accountId: number): Promise<unknown> {
  return backendDelete(`cash-bank-accounts/${accountId}/bank-details`);
}

export function activateBankDetails(accountId: number): Promise<unknown> {
  return backendPost(`cash-bank-accounts/${accountId}/activate`);
}

export function deactivateBankDetails(accountId: number): Promise<unknown> {
  return backendPost(`cash-bank-accounts/${accountId}/deactivate`);
}

export type CashBankTransactionListQuery = {
  transaction_type?: CashBankTransactionTypeValue;
  status?: CashBankTransactionStatusValue;
  account_id?: number;
  from?: string;
  to?: string;
  reference?: string;
  page?: number;
  per_page?: number;
};

export function fetchCashBankTransactions(
  query: CashBankTransactionListQuery,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    transaction_type: query.transaction_type || undefined,
    status: query.status || undefined,
    account_id: query.account_id,
    from: query.from || undefined,
    to: query.to || undefined,
    reference: query.reference || undefined,
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("cash-bank-transactions", { query: params, signal });
}

export function fetchCashBankTransaction(
  transactionId: number,
  signal?: AbortSignal,
): Promise<unknown> {
  return backendGet(`cash-bank-transactions/${transactionId}`, { signal });
}

export function createCashBankTransaction(
  type: CashBankTransactionTypeValue,
  payload: CashBankTransactionWritePayload,
): Promise<unknown> {
  return backendPost(`cash-bank-transactions/${TRANSACTION_ENDPOINT[type]}`, payload);
}

export function updateCashBankTransaction(
  transactionId: number,
  payload: CashBankTransactionWritePayload,
): Promise<unknown> {
  return backendPut(`cash-bank-transactions/${transactionId}`, payload);
}

export function deleteCashBankTransaction(transactionId: number): Promise<unknown> {
  return backendDelete(`cash-bank-transactions/${transactionId}`);
}

/** Post a draft into the accounting record. No body is accepted. */
export function postCashBankTransaction(transactionId: number): Promise<unknown> {
  return backendPost(`cash-bank-transactions/${transactionId}/post`);
}

/** One account's ledger balance (perm `accounting.ledger.view`). */
export function fetchAccountBalance(accountId: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`accounting/accounts/${accountId}/balance`, { signal });
}
