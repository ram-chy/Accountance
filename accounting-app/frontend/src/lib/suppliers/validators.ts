/**
 * Runtime validation for supplier responses and write payloads.
 *
 * API JSON is untrusted. `parseSupplier` narrows one row to the exact fields the
 * UI renders; a row missing a required identifier (`id`, `supplier_code`, `name`,
 * `is_active`) is dropped rather than fabricated. Write validation mirrors
 * `Store/UpdateSupplierRequest` for immediate feedback; the backend remains the
 * validator of record.
 */

import { normalizeList } from "@/lib/api/pagination";

import type {
  Supplier,
  SupplierAccountRef,
  SupplierListState,
  SupplierWritePayload,
} from "./types";

export class SupplierDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "SupplierDataError";
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

function parseAccountRef(value: unknown): SupplierAccountRef | null | undefined {
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

export function parseSupplier(value: unknown): Supplier | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const supplier_code = typeof value.supplier_code === "string" ? value.supplier_code : undefined;
  const name = typeof value.name === "string" ? value.name : undefined;
  const payable_account_id = isNumber(value.payable_account_id)
    ? value.payable_account_id
    : undefined;
  const is_active = typeof value.is_active === "boolean" ? value.is_active : undefined;

  if (
    id === undefined ||
    supplier_code === undefined ||
    name === undefined ||
    payable_account_id === undefined ||
    is_active === undefined
  ) {
    return null;
  }

  const payable_account = parseAccountRef(value.payable_account);

  if (payable_account === undefined) {
    return null;
  }

  return {
    id,
    supplier_code,
    name,
    email: asNullableString(value.email),
    phone: asNullableString(value.phone),
    address: asNullableString(value.address),
    city: asNullableString(value.city),
    state: asNullableString(value.state),
    country_code: asNullableString(value.country_code),
    tax_identifier: asNullableString(value.tax_identifier),
    payable_account_id,
    is_active,
    payable_account,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toErrorState(message: string): SupplierListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

export function parseSupplierList(raw: unknown, perPage: number): SupplierListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toErrorState("The suppliers response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: Supplier[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const supplier = parseSupplier(entry);
    if (supplier === null) {
      skipped += 1;
      continue;
    }
    items.push(supplier);
  }

  if (skipped > 0 && items.length === 0) {
    return toErrorState("The suppliers response contained no valid rows.");
  }

  return { status: "ready", items, hasMore: list.hasMore, perPage };
}

export function parseSupplierOrThrow(raw: unknown, label = "supplier"): Supplier {
  const supplier = parseSupplier(raw);
  if (supplier === null) {
    throw new SupplierDataError(`The ${label} response was invalid.`);
  }
  return supplier;
}

export type SupplierValidationErrors = Record<string, string>;

const CODE_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]*$/;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Mirrors the verified `Store/UpdateSupplierRequest` rules. */
export function validateSupplierWrite(payload: SupplierWritePayload): SupplierValidationErrors {
  const errors: SupplierValidationErrors = {};

  const code = payload.supplier_code.trim();
  if (code === "") {
    errors.supplier_code = "The supplier code is required.";
  } else if (code.length > 50) {
    errors.supplier_code = "The supplier code may not be longer than 50 characters.";
  } else if (!CODE_PATTERN.test(code)) {
    errors.supplier_code =
      "The supplier code must start with a letter or digit and may contain letters, digits, dots, underscores and hyphens.";
  }

  const name = payload.name.trim();
  if (name === "") {
    errors.name = "The supplier name is required.";
  } else if (name.length > 255) {
    errors.name = "The supplier name may not be longer than 255 characters.";
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
    payload.payable_account_id === null ||
    !Number.isInteger(payload.payable_account_id) ||
    payload.payable_account_id <= 0
  ) {
    errors.payable_account_id = "Select a payable account.";
  }

  return errors;
}
