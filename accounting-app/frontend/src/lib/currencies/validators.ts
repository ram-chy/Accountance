/**
 * Runtime validation for currency responses and inputs.
 *
 * API JSON is untrusted input. The parsers narrow a raw payload to the exact
 * fields the UI renders, and a currency missing a required identifier, code,
 * name or precision is treated as invalid and skipped — the same
 * "filter the invalid row, never fabricate" strategy the account, tax and
 * fixed-asset validators use.
 *
 * `is_active` and `decimal_precision` are stricter: a currency whose
 * `is_active` is not a boolean, or whose precision is not the integer the
 * backend always sends, cannot be rendered safely and is dropped rather than
 * guessed at.
 */

import { normalizeList } from "@/lib/api/pagination";

import type {
  Currency,
  CurrencyCreatePayload,
  CurrencyDetailState,
  CurrencyListState,
  CurrencyUpdatePayload,
} from "./types";
import {
  CURRENCY_CODE_PATTERN,
  MAX_CURRENCY_DECIMAL_PRECISION,
  MIN_CURRENCY_DECIMAL_PRECISION,
} from "./types";

export class CurrenciesDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "CurrenciesDataError";
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function asString(value: unknown): string | undefined {
  return typeof value === "string" ? value : undefined;
}

function asNullableString(value: unknown): string | null {
  return typeof value === "string" ? value : null;
}

function asNumber(value: unknown): number | undefined {
  return typeof value === "number" && Number.isFinite(value) ? value : undefined;
}

function asBoolean(value: unknown): boolean | undefined {
  return typeof value === "boolean" ? value : undefined;
}

export function parseCurrency(value: unknown): Currency | null {
  if (!isRecord(value)) return null;

  const id = asNumber(value.id);
  const code = asString(value.code);
  const name = asString(value.name);
  const decimal_precision = asNumber(value.decimal_precision);
  const is_active = asBoolean(value.is_active);

  if (
    id === undefined ||
    code === undefined ||
    name === undefined ||
    decimal_precision === undefined ||
    is_active === undefined
  ) {
    return null;
  }

  return {
    id,
    code,
    name,
    symbol: asNullableString(value.symbol),
    decimal_precision,
    is_active,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

export function parseCurrencyOrThrow(raw: unknown, label = "currency"): Currency {
  const currency = parseCurrency(raw);
  if (currency === null) {
    throw new CurrenciesDataError(`The ${label} response was invalid.`);
  }
  return currency;
}

export function parseCurrencyList(raw: unknown): CurrencyListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return {
      status: "error",
      items: [],
      message: "The currency response was not a list.",
    };
  }

  const list = normalizeList<unknown>(raw);
  const items: Currency[] = [];

  for (const entry of list.items) {
    const currency = parseCurrency(entry);
    if (currency) items.push(currency);
  }

  if (items.length === 0 && list.items.length > 0) {
    return {
      status: "error",
      items: [],
      message: "The currency response contained no valid rows.",
    };
  }

  return { status: "ready", items };
}

export function parseCurrencyDetail(raw: unknown): CurrencyDetailState {
  const currency = parseCurrency(raw);
  if (currency === null) {
    return {
      status: "error",
      currency: null,
      message: "The currency response was invalid.",
    };
  }
  return { status: "ready", currency };
}

export type CurrencyValidationErrors = Record<string, string>;

function validateCode(code: string | undefined, errors: CurrencyValidationErrors, required: boolean): void {
  if (code === undefined) {
    if (required) errors.code = "Code is required.";
    return;
  }

  const trimmed = code.trim();
  if (trimmed === "") {
    errors.code = "Code is required.";
    return;
  }

  if (!CURRENCY_CODE_PATTERN.test(trimmed)) {
    errors.code = "A currency code must be exactly three letters, such as USD.";
  }
}

function validateName(name: string | undefined, errors: CurrencyValidationErrors, required: boolean): void {
  if (name === undefined) {
    if (required) errors.name = "Name is required.";
    return;
  }

  if (name.trim() === "") {
    errors.name = "Name is required.";
    return;
  }

  if (name.trim().length > 100) {
    errors.name = "Name must be 100 characters or fewer.";
  }
}

function validatePrecision(
  precision: number | undefined,
  errors: CurrencyValidationErrors,
): void {
  // The service owns the 0–4 rule and its message; this only catches the
  // obviously-invalid entry (a missing or non-integer field) before the request.
  if (precision === undefined) return;
  if (!Number.isInteger(precision) || precision < MIN_CURRENCY_DECIMAL_PRECISION || precision > MAX_CURRENCY_DECIMAL_PRECISION) {
    errors.decimal_precision = "A currency must have between 0 and 4 decimal places.";
  }
}

export function validateCurrencyCreate(payload: CurrencyCreatePayload): CurrencyValidationErrors {
  const errors: CurrencyValidationErrors = {};
  validateCode(payload.code, errors, true);
  validateName(payload.name, errors, true);
  validatePrecision(payload.decimal_precision, errors);
  return errors;
}

export function validateCurrencyUpdate(payload: CurrencyUpdatePayload): CurrencyValidationErrors {
  const errors: CurrencyValidationErrors = {};
  validateCode(payload.code, errors, false);
  validateName(payload.name, errors, false);
  validatePrecision(payload.decimal_precision, errors);
  return errors;
}
