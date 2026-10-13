/**
 * Runtime validation for customer responses and write payloads.
 *
 * API JSON is untrusted. `parseCustomer` narrows one row to the exact fields
 * the UI renders; a row missing a required identifier (`id`, `customer_code`,
 * `name`, `is_active`) is dropped rather than fabricated. Write validation
 * mirrors `Store/UpdateCustomerRequest` for immediate feedback; the backend
 * remains the validator of record.
 */

import { normalizeList } from "@/lib/api/pagination";

import type {
  Customer,
  CustomerAccountRef,
  CustomerListState,
  CustomerWritePayload,
} from "./types";

export class CustomerDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "CustomerDataError";
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

function parseAccountRef(value: unknown): CustomerAccountRef | null | undefined {
  if (value === null || value === undefined) {
    return null;
  }
  if (!isRecord(value)) {
    return undefined;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const code = typeof value.code === "string" ? value.code : undefined;
  const name = typeof value.name === "string" ? value.name : undefined;

  if (id === undefined || code === undefined || name === undefined) {
    return undefined;
  }

  return { id, code, name };
}

export function parseCustomer(value: unknown): Customer | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const customer_code = typeof value.customer_code === "string" ? value.customer_code : undefined;
  const name = typeof value.name === "string" ? value.name : undefined;
  const receivable_account_id = isNumber(value.receivable_account_id)
    ? value.receivable_account_id
    : undefined;
  const is_active = typeof value.is_active === "boolean" ? value.is_active : undefined;

  if (
    id === undefined ||
    customer_code === undefined ||
    name === undefined ||
    receivable_account_id === undefined ||
    is_active === undefined
  ) {
    return null;
  }

  const receivable_account = parseAccountRef(value.receivable_account);

  if (receivable_account === undefined) {
    return null;
  }

  return {
    id,
    customer_code,
    name,
    email: asNullableString(value.email),
    phone: asNullableString(value.phone),
    address: asNullableString(value.address),
    city: asNullableString(value.city),
    state: asNullableString(value.state),
    country_code: asNullableString(value.country_code),
    tax_identifier: asNullableString(value.tax_identifier),
    receivable_account_id,
    is_active,
    receivable_account,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toErrorState(message: string): CustomerListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

export function parseCustomerList(raw: unknown, perPage: number): CustomerListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toErrorState("The customers response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: Customer[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const customer = parseCustomer(entry);
    if (customer === null) {
      skipped += 1;
      continue;
    }
    items.push(customer);
  }

  if (skipped > 0 && items.length === 0) {
    return toErrorState("The customers response contained no valid rows.");
  }

  return { status: "ready", items, hasMore: list.hasMore, perPage };
}

export function parseCustomerOrThrow(raw: unknown, label = "customer"): Customer {
  const customer = parseCustomer(raw);
  if (customer === null) {
    throw new CustomerDataError(`The ${label} response was invalid.`);
  }
  return customer;
}

export type CustomerValidationErrors = Record<string, string>;

const CODE_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]*$/;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Mirrors the verified `Store/UpdateCustomerRequest` rules. */
export function validateCustomerWrite(payload: CustomerWritePayload): CustomerValidationErrors {
  const errors: CustomerValidationErrors = {};

  const code = payload.customer_code.trim();
  if (code === "") {
    errors.customer_code = "The customer code is required.";
  } else if (code.length > 50) {
    errors.customer_code = "The customer code may not be longer than 50 characters.";
  } else if (!CODE_PATTERN.test(code)) {
    errors.customer_code =
      "The customer code must start with a letter or digit and may contain letters, digits, dots, underscores and hyphens.";
  }

  const name = payload.name.trim();
  if (name === "") {
    errors.name = "The customer name is required.";
  } else if (name.length > 255) {
    errors.name = "The customer name may not be longer than 255 characters.";
  }

  if (payload.email !== null && payload.email !== "" && !EMAIL_PATTERN.test(payload.email)) {
    errors.email = "Enter a valid email address.";
  }

  if (
    payload.country_code !== null &&
    payload.country_code !== "" &&
    !/^[A-Za-z]{2}$/.test(payload.country_code)
  ) {
    errors.country_code = "The country code must be exactly 2 letters.";
  }

  if (
    payload.receivable_account_id === null ||
    !Number.isInteger(payload.receivable_account_id) ||
    payload.receivable_account_id <= 0
  ) {
    errors.receivable_account_id = "Select a receivable account.";
  }

  return errors;
}
