/**
 * Runtime validation for exchange-rate responses and inputs.
 *
 * API JSON is untrusted input. The parsers narrow a raw payload to the exact
 * fields the UI renders, and a rate missing an identifier, a pair id, a date or
 * the decimal rate string is treated as invalid and skipped — the same
 * "filter the invalid row, never fabricate" strategy the other validators use.
 *
 * The `rate` is validated as a string, never coerced through `Number`: it is
 * rendered verbatim and the backend owns its arithmetic. Only the shallow
 * "well-formed and greater than zero" check is done here.
 */

import { normalizeList } from "@/lib/api/pagination";

import type {
  ExchangeRate,
  ExchangeRateCreatePayload,
  ExchangeRateDetailState,
  ExchangeRateListState,
  ExchangeRateUpdatePayload,
} from "./types";
import { RATE_PATTERN } from "./types";

export class ExchangeRatesDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "ExchangeRatesDataError";
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

function asNullableNumber(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

function asBoolean(value: unknown): boolean | undefined {
  return typeof value === "boolean" ? value : undefined;
}

export function parseExchangeRate(value: unknown): ExchangeRate | null {
  if (!isRecord(value)) return null;

  const id = asNumber(value.id);
  const from_currency_id = asNumber(value.from_currency_id);
  const to_currency_id = asNumber(value.to_currency_id);
  const effective_date = asString(value.effective_date);
  const rate = asString(value.rate);
  const is_active = asBoolean(value.is_active);

  if (
    id === undefined ||
    from_currency_id === undefined ||
    to_currency_id === undefined ||
    effective_date === undefined ||
    rate === undefined ||
    is_active === undefined
  ) {
    return null;
  }

  return {
    id,
    from_currency_id,
    to_currency_id,
    from_currency: asNullableString(value.from_currency),
    to_currency: asNullableString(value.to_currency),
    pair: asNullableString(value.pair),
    effective_date,
    rate,
    is_active,
    source: asNullableString(value.source),
    created_by: asNullableNumber(value.created_by),
    updated_by: asNullableNumber(value.updated_by),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

export function parseExchangeRateOrThrow(raw: unknown, label = "exchange rate"): ExchangeRate {
  const rate = parseExchangeRate(raw);
  if (rate === null) {
    throw new ExchangeRatesDataError(`The ${label} response was invalid.`);
  }
  return rate;
}

export function parseExchangeRateList(raw: unknown): ExchangeRateListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return {
      status: "error",
      items: [],
      message: "The exchange rate response was not a list.",
    };
  }

  const list = normalizeList<unknown>(raw);
  const items: ExchangeRate[] = [];

  for (const entry of list.items) {
    const rate = parseExchangeRate(entry);
    if (rate) items.push(rate);
  }

  if (items.length === 0 && list.items.length > 0) {
    return {
      status: "error",
      items: [],
      message: "The exchange rate response contained no valid rows.",
    };
  }

  return { status: "ready", items };
}

export function parseExchangeRateDetail(raw: unknown): ExchangeRateDetailState {
  const rate = parseExchangeRate(raw);
  if (rate === null) {
    return {
      status: "error",
      rate: null,
      message: "The exchange rate response was invalid.",
    };
  }
  return { status: "ready", rate };
}

export type ExchangeRateValidationErrors = Record<string, string>;

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/** True for a well-formed decimal that is greater than zero. */
function isPositiveRate(value: string): boolean {
  const trimmed = value.trim();
  if (!RATE_PATTERN.test(trimmed)) return false;
  return /[1-9]/.test(trimmed);
}

export function validateExchangeRateCreate(
  payload: ExchangeRateCreatePayload,
): ExchangeRateValidationErrors {
  const errors: ExchangeRateValidationErrors = {};

  if (!Number.isInteger(payload.from_currency_id) || payload.from_currency_id <= 0) {
    errors.from_currency_id = "Select the currency to convert from.";
  }

  if (!Number.isInteger(payload.to_currency_id) || payload.to_currency_id <= 0) {
    errors.to_currency_id = "Select the currency to convert to.";
  }

  if (!isPositiveRate(payload.rate ?? "")) {
    errors.rate = "Enter a rate greater than zero (up to 10 decimal places).";
  }

  if (!DATE_PATTERN.test(payload.effective_date ?? "")) {
    errors.effective_date = "Choose an effective date.";
  }

  return errors;
}

export function validateExchangeRateUpdate(
  payload: ExchangeRateUpdatePayload,
): ExchangeRateValidationErrors {
  const errors: ExchangeRateValidationErrors = {};

  if (payload.rate !== undefined && !isPositiveRate(payload.rate)) {
    errors.rate = "Enter a rate greater than zero (up to 10 decimal places).";
  }

  if (payload.effective_date !== undefined && !DATE_PATTERN.test(payload.effective_date)) {
    errors.effective_date = "Choose an effective date.";
  }

  return errors;
}
