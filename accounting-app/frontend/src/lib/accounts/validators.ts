/**
 * Runtime validation for chart of accounts responses and inputs.
 *
 * API JSON is untrusted input. The parsers below narrow a raw payload to the
 * exact fields the UI renders. A row missing a required identifier (id, code,
 * name, account type or the effective normal balance) or carrying an unknown
 * enum value is treated as invalid and skipped — the same "filter the invalid
 * row, never fabricate" strategy the dashboard validators use, because showing
 * a guess would be worse than showing slightly fewer accounts. Write payloads
 * are checked against the backend's own rules for immediate client feedback;
 * the backend remains the validator of record.
 */

import { normalizeList, type ListResult } from "@/lib/api/pagination";
import {
  ACCOUNT_TYPE_VALUES,
  CASH_BANK_KIND_VALUES,
  NORMAL_BALANCE_VALUES,
  type Account,
  type AccountListState,
  type AccountTypeMeta,
  type AccountTypeValue,
  type AccountWritePayload,
  type CashBankKindValue,
  type NormalBalanceValue,
} from "./types";

export class AccountsDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "AccountsDataError";
  }
}

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function isNumber(value: unknown): value is number {
  return typeof value === "number" && Number.isFinite(value);
}

function asNullableString(value: unknown): string | null {
  return typeof value === "string" ? value : null;
}

function asBoolean(value: unknown): boolean | undefined {
  return typeof value === "boolean" ? value : undefined;
}

function asAccountType(value: unknown): AccountTypeValue | undefined {
  return ACCOUNT_TYPE_VALUES.includes(value as AccountTypeValue)
    ? (value as AccountTypeValue)
    : undefined;
}

function asNormalBalance(value: unknown): NormalBalanceValue | undefined {
  return NORMAL_BALANCE_VALUES.includes(value as NormalBalanceValue)
    ? (value as NormalBalanceValue)
    : undefined;
}

function asCashBankKind(value: unknown): CashBankKindValue | null | undefined {
  if (value === null) {
    return null;
  }

  return CASH_BANK_KIND_VALUES.includes(value as CashBankKindValue)
    ? (value as CashBankKindValue)
    : undefined;
}

/**
 * Narrow one account row, or return null when the row is not safe to render.
 *
 * All identifiers and the effective normal balance are required. Optional text
 * (description, timestamps) and the nullable parent/kind degrade to null; the
 * lifecycle booleans must be real booleans because the UI renders them as
 * status and would otherwise mislabel an account.
 */
export function parseAccount(value: unknown): Account | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const code = typeof value.code === "string" ? value.code : undefined;
  const name = typeof value.name === "string" ? value.name : undefined;
  const account_type = asAccountType(value.account_type);
  const normal_balance = asNormalBalance(value.normal_balance);
  const is_active = asBoolean(value.is_active);
  const is_system = asBoolean(value.is_system);
  const has_journal_history = asBoolean(value.has_journal_history);
  const is_cash_bank_account = asBoolean(value.is_cash_bank_account);
  const cash_bank_kind = asCashBankKind(value.cash_bank_kind);

  if (
    id === undefined ||
    code === undefined ||
    name === undefined ||
    account_type === undefined ||
    normal_balance === undefined ||
    is_active === undefined ||
    is_system === undefined ||
    has_journal_history === undefined ||
    is_cash_bank_account === undefined ||
    cash_bank_kind === undefined
  ) {
    return null;
  }

  return {
    id,
    code,
    name,
    account_type,
    cash_bank_kind,
    is_cash_bank_account,
    normal_balance,
    is_contra: value.is_contra === true,
    parent_id: isNumber(value.parent_id) ? value.parent_id : null,
    description: asNullableString(value.description),
    is_active,
    is_system,
    has_journal_history,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toErrorState(message: string): AccountListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

/**
 * Narrow the flat (or future paginator-shaped) list payload into a validated
 * `AccountListState`. Invalid rows are skipped, never fabricated; the perPage
 * used to infer `hasMore` comes from the caller so a short page can be
 * recognised correctly.
 */
export function parseAccountList(raw: unknown, perPage: number): AccountListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toErrorState("The chart of accounts response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: Account[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const account = parseAccount(entry);

    if (account === null) {
      skipped += 1;
      continue;
    }

    items.push(account);
  }

  if (skipped > 0 && items.length === 0) {
    return toErrorState(
      "The chart of accounts response contained no valid account rows.",
    );
  }

  return {
    status: "ready",
    items,
    hasMore: list.hasMore,
    perPage,
  };
}

/** Narrow `GET /api/accounts/types` (a flat array of `{value,normal_balance}`). */
export function parseAccountTypes(raw: unknown): AccountTypeMeta[] {
  if (!Array.isArray(raw)) {
    return [];
  }

  const types: AccountTypeMeta[] = [];

  for (const entry of raw) {
    if (!isRecord(entry)) {
      continue;
    }

    const value = asAccountType(entry.value);
    const normal_balance = asNormalBalance(entry.normal_balance);

    if (value === undefined || normal_balance === undefined) {
      continue;
    }

    types.push({ value, normal_balance });
  }

  return types;
}

/** Wrap a single write-response payload; returns null when malformed. */
export function parseAccountOrThrow(raw: unknown, label = "account"): Account {
  const account = parseAccount(raw);

  if (account === null) {
    throw new AccountsDataError(`The ${label} response was invalid.`);
  }

  return account;
}

export type AccountValidationErrors = Record<string, string>;

const CODE_PATTERN = /^[A-Za-z0-9._\- ]+$/;

/**
 * Client-side validation mirroring `StoreAccountRequest` / `UpdateAccountRequest`.
 *
 * This is feedback only — the backend re-validates every write. Rules are kept
 * identical to the verified Laravel rules so a client pass means the request is
 * very likely to pass server-side too.
 */
export function validateAccountWrite(
  payload: AccountWritePayload,
): AccountValidationErrors {
  const errors: AccountValidationErrors = {};

  if (!payload.code.trim()) {
    errors.code = "The account code is required.";
  } else if (payload.code.length > 50) {
    errors.code = "The account code may not be longer than 50 characters.";
  } else if (!CODE_PATTERN.test(payload.code)) {
    errors.code =
      "The account code may contain letters, numbers, dots, dashes and spaces only.";
  }

  if (!payload.name.trim()) {
    errors.name = "The account name is required.";
  } else if (payload.name.length > 255) {
    errors.name = "The account name may not be longer than 255 characters.";
  }

  if (!ACCOUNT_TYPE_VALUES.includes(payload.account_type)) {
    errors.account_type =
      "The account type must be one of ASSET, LIABILITY, EQUITY, REVENUE or EXPENSE.";
  }

  if (payload.cash_bank_kind !== null) {
    if (!CASH_BANK_KIND_VALUES.includes(payload.cash_bank_kind)) {
      errors.cash_bank_kind =
        "The cash/bank classification must be CASH or BANK, or left empty.";
    }
  }

  if (payload.normal_balance !== null) {
    if (!NORMAL_BALANCE_VALUES.includes(payload.normal_balance)) {
      errors.normal_balance =
        "The normal balance must be DEBIT or CREDIT, or left empty.";
    }
  }

  if (payload.description !== null && payload.description.length > 1000) {
    errors.description =
      "The description may not be longer than 1000 characters.";
  }

  return errors;
}

/** Extract a compact `ListResult` view for places that need only items+hasMore. */
export function toListResult(state: AccountListState): ListResult<Account> {
  return {
    items: state.items,
    perPage: state.perPage,
    hasMore: state.hasMore,
  };
}