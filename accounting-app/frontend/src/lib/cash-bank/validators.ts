/**
 * Runtime validation for cash & bank responses and inputs.
 *
 * API JSON is untrusted input. The parsers below narrow a raw payload to the
 * exact fields the UI renders. A row missing a required identifier (id, number,
 * amount, date, type, status) or carrying an unknown enum value is treated as
 * invalid and skipped — the same "filter the invalid row, never fabricate"
 * strategy the accounts and journals validators use, because showing a guess
 * would be worse than showing slightly fewer rows. Write payloads are checked
 * against the backend's own rules for immediate client feedback; the backend
 * remains the validator of record.
 */

import { isDateOnly } from "@/lib/format/date";
import { isDecimalString } from "@/lib/format/money";
import { normalizeList } from "@/lib/api/pagination";

import {
  CASH_BANK_KIND_VALUES,
  CASH_BANK_TRANSACTION_STATUS_VALUES,
  CASH_BANK_TRANSACTION_TYPE_VALUES,
  type AccountBalance,
  type BankAccountDetails,
  type CashBankAccount,
  type CashBankAccountClassifyPayload,
  type CashBankAccountListState,
  type CashBankKindValue,
  type CashBankTransaction,
  type CashBankTransactionListState,
  type CashBankTransactionStatusValue,
  type CashBankTransactionTypeValue,
  type CashBankTransactionWritePayload,
  type TransactionAccountRef,
} from "./types";

export class CashBankDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "CashBankDataError";
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
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

function asKind(value: unknown): CashBankKindValue | null | undefined {
  if (value === null) {
    return null;
  }

  return CASH_BANK_KIND_VALUES.includes(value as CashBankKindValue)
    ? (value as CashBankKindValue)
    : undefined;
}

function asTransactionType(value: unknown): CashBankTransactionTypeValue | undefined {
  return CASH_BANK_TRANSACTION_TYPE_VALUES.includes(value as CashBankTransactionTypeValue)
    ? (value as CashBankTransactionTypeValue)
    : undefined;
}

function asTransactionStatus(value: unknown): CashBankTransactionStatusValue | undefined {
  return CASH_BANK_TRANSACTION_STATUS_VALUES.includes(value as CashBankTransactionStatusValue)
    ? (value as CashBankTransactionStatusValue)
    : undefined;
}

function parseBankAccountDetails(value: unknown): BankAccountDetails | null | undefined {
  if (value === null) {
    return null;
  }

  if (!isRecord(value)) {
    return undefined;
  }

  const account_name = typeof value.account_name === "string" ? value.account_name : undefined;
  const bank_name = typeof value.bank_name === "string" ? value.bank_name : undefined;
  const is_active = asBoolean(value.is_active);

  if (account_name === undefined || bank_name === undefined || is_active === undefined) {
    return undefined;
  }

  return {
    account_name,
    bank_name,
    account_number: asNullableString(value.account_number),
    branch: asNullableString(value.branch),
    bank_identifier: asNullableString(value.bank_identifier),
    is_active,
  };
}

export function parseCashBankAccount(value: unknown): CashBankAccount | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const code = typeof value.code === "string" ? value.code : undefined;
  const name = typeof value.name === "string" ? value.name : undefined;
  const account_type = typeof value.account_type === "string" ? value.account_type : undefined;
  const normal_balance = typeof value.normal_balance === "string" ? value.normal_balance : undefined;
  const is_active = asBoolean(value.is_active);
  const cash_bank_kind = asKind(value.cash_bank_kind);
  const bank_account = parseBankAccountDetails(value.bank_account);

  if (
    id === undefined ||
    code === undefined ||
    name === undefined ||
    account_type === undefined ||
    normal_balance === undefined ||
    is_active === undefined ||
    cash_bank_kind === undefined ||
    bank_account === undefined
  ) {
    return null;
  }

  return {
    id,
    code,
    name,
    account_type,
    normal_balance,
    cash_bank_kind,
    is_active,
    description: asNullableString(value.description),
    bank_account,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toAccountErrorState(message: string): CashBankAccountListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

export function parseCashBankAccountList(raw: unknown, perPage: number): CashBankAccountListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toAccountErrorState("The cash and bank accounts response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: CashBankAccount[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const account = parseCashBankAccount(entry);

    if (account === null) {
      skipped += 1;
      continue;
    }

    items.push(account);
  }

  if (skipped > 0 && items.length === 0) {
    return toAccountErrorState("The cash and bank accounts response contained no valid rows.");
  }

  return { status: "ready", items, hasMore: list.hasMore, perPage };
}

export function parseCashBankAccountOrThrow(raw: unknown, label = "account"): CashBankAccount {
  const account = parseCashBankAccount(raw);

  if (account === null) {
    throw new CashBankDataError(`The ${label} response was invalid.`);
  }

  return account;
}

function parseTransactionAccountRef(value: unknown): TransactionAccountRef | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const code = typeof value.code === "string" ? value.code : undefined;
  const name = typeof value.name === "string" ? value.name : undefined;

  if (id === undefined || code === undefined || name === undefined) {
    return null;
  }

  return { id, code, name };
}

/**
 * Narrow one transaction row, or return null when it is not safe to render.
 *
 * Required: the identifiers and the financial facts (type, date, amount,
 * status). Optional text and the nullable relations degrade to null.
 */
export function parseCashBankTransaction(value: unknown): CashBankTransaction | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const transaction_number =
    typeof value.transaction_number === "string" ? value.transaction_number : undefined;
  const transaction_type = asTransactionType(value.transaction_type);
  const transaction_date = isDateOnly(value.transaction_date) ? value.transaction_date : undefined;
  const source_account_id = isNumber(value.source_account_id) ? value.source_account_id : undefined;
  const destination_account_id = isNumber(value.destination_account_id)
    ? value.destination_account_id
    : undefined;
  const amount = isDecimalString(value.amount) ? value.amount : undefined;
  const status = asTransactionStatus(value.status);

  if (
    id === undefined ||
    transaction_number === undefined ||
    transaction_type === undefined ||
    transaction_date === undefined ||
    source_account_id === undefined ||
    destination_account_id === undefined ||
    amount === undefined ||
    status === undefined
  ) {
    return null;
  }

  return {
    id,
    transaction_number,
    transaction_type,
    transaction_date,
    source_account_id,
    destination_account_id,
    amount,
    status,
    reference: asNullableString(value.reference),
    notes: asNullableString(value.notes),
    source_account: parseTransactionAccountRef(value.source_account),
    destination_account: parseTransactionAccountRef(value.destination_account),
    journal_id: isNumber(value.journal_id) ? value.journal_id : null,
    posted_at: asNullableString(value.posted_at),
    created_by: isNumber(value.created_by) ? value.created_by : null,
    posted_by: isNumber(value.posted_by) ? value.posted_by : null,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toTransactionErrorState(message: string): CashBankTransactionListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

export function parseCashBankTransactionList(
  raw: unknown,
  perPage: number,
): CashBankTransactionListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toTransactionErrorState("The cash and bank transactions response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: CashBankTransaction[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const transaction = parseCashBankTransaction(entry);

    if (transaction === null) {
      skipped += 1;
      continue;
    }

    items.push(transaction);
  }

  if (skipped > 0 && items.length === 0) {
    return toTransactionErrorState(
      "The cash and bank transactions response contained no valid rows.",
    );
  }

  return { status: "ready", items, hasMore: list.hasMore, perPage };
}

export function parseCashBankTransactionOrThrow(
  raw: unknown,
  label = "transaction",
): CashBankTransaction {
  const transaction = parseCashBankTransaction(raw);

  if (transaction === null) {
    throw new CashBankDataError(`The ${label} response was invalid.`);
  }

  return transaction;
}

/** Narrow `GET /accounting/accounts/{account}/balance`; null when malformed. */
export function parseAccountBalance(raw: unknown): AccountBalance | null {
  if (!isRecord(raw)) {
    return null;
  }

  const account_id = isNumber(raw.account_id) ? raw.account_id : undefined;
  const balance = isDecimalString(raw.balance) ? raw.balance : undefined;

  if (account_id === undefined || balance === undefined) {
    return null;
  }

  return {
    account_id,
    code: typeof raw.code === "string" ? raw.code : "",
    name: typeof raw.name === "string" ? raw.name : "",
    account_type: typeof raw.account_type === "string" ? raw.account_type : "",
    normal_balance: typeof raw.normal_balance === "string" ? raw.normal_balance : "",
    is_active: raw.is_active === true,
    total_debit: isDecimalString(raw.total_debit) ? raw.total_debit : "0",
    total_credit: isDecimalString(raw.total_credit) ? raw.total_credit : "0",
    balance,
  };
}

export type CashBankValidationErrors = Record<string, string>;

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const AMOUNT_PATTERN = /^\d+(?:\.\d{1,4})?$/;

/**
 * Client-side validation mirroring `StoreCashBankTransactionRequest` /
 * `UpdateCashBankTransactionRequest` on top of `ValidatesTransactionAmounts`.
 *
 * Feedback only — the backend re-validates every write, including the
 * eligibility rule (which side must be cash/bank) that is not expressible as a
 * field rule. Rules are kept identical to the verified Laravel rules so a
 * client pass means the request is very likely to pass server-side too.
 */
export function validateCashBankTransactionWrite(
  payload: CashBankTransactionWritePayload,
): CashBankValidationErrors {
  const errors: CashBankValidationErrors = {};

  if (!DATE_PATTERN.test(payload.transaction_date.trim())) {
    errors.transaction_date = "The transaction date must be in YYYY-MM-DD format.";
  }

  const amount = payload.amount.trim();

  if (amount === "") {
    errors.amount = "The amount is required.";
  } else if (!AMOUNT_PATTERN.test(amount)) {
    errors.amount = "The amount must be a positive number with at most 4 decimal places.";
  } else if (/^0+(?:\.0+)?$/.test(amount)) {
    errors.amount = "The amount must be greater than zero.";
  }

  if (!Number.isInteger(payload.source_account_id) || payload.source_account_id <= 0) {
    errors.source_account_id = "The source account is required.";
  }

  if (!Number.isInteger(payload.destination_account_id) || payload.destination_account_id <= 0) {
    errors.destination_account_id = "The destination account is required.";
  }

  if (
    Number.isInteger(payload.source_account_id) &&
    Number.isInteger(payload.destination_account_id) &&
    payload.source_account_id > 0 &&
    payload.source_account_id === payload.destination_account_id
  ) {
    errors.destination_account_id =
      "The source and destination accounts must be different. " +
      "Moving money to the account it came from does not change anything.";
  }

  if (payload.reference !== null && payload.reference.length > 255) {
    errors.reference = "The reference may not be longer than 255 characters.";
  }

  if (payload.notes !== null && payload.notes.length > 5000) {
    errors.notes = "The notes may not be longer than 5000 characters.";
  }

  return errors;
}

/**
 * Client-side mirror of `UpdateCashBankAccountRequest`: a bank block, when
 * supplied, needs an account name and a bank name. A null block or a null kind
 * is a deliberate removal and is allowed here; whether removal is permitted is
 * the service's decision and is surfaced from the backend.
 */
export function validateCashBankAccountClassify(
  payload: CashBankAccountClassifyPayload,
): CashBankValidationErrors {
  const errors: CashBankValidationErrors = {};

  if (payload.bank_account) {
    if (!payload.bank_account.account_name.trim()) {
      errors["bank_account.account_name"] = "The bank account name is required.";
    }

    if (!payload.bank_account.bank_name.trim()) {
      errors["bank_account.bank_name"] = "The bank name is required.";
    }
  }

  if (payload.cash_bank_kind !== undefined && payload.cash_bank_kind !== null) {
    if (!CASH_BANK_KIND_VALUES.includes(payload.cash_bank_kind)) {
      errors.cash_bank_kind = "The classification must be CASH or BANK, or left empty.";
    }
  }

  return errors;
}
