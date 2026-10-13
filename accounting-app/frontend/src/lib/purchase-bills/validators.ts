/**
 * Runtime validation for purchase bill responses and write payloads.
 *
 * Response parsing narrows one row to the exact fields the UI renders. A row
 * missing a required identifier or carrying an unknown status is dropped rather
 * than fabricated. Write validation mirrors `StorePurchaseBillRequest` (and the
 * line guards in `DocumentCalculator`) for immediate feedback; the backend is
 * still the validator of record and owns every total.
 */

import { normalizeList } from "@/lib/api/pagination";
import { isDateOnly } from "@/lib/format/date";
import { isDecimalString } from "@/lib/format/money";

import {
  PURCHASE_BILL_STATUS_VALUES,
  type PurchaseBill,
  type PurchaseBillLine,
  type PurchaseBillLinePayload,
  type PurchaseBillListState,
  type PurchaseBillStatus,
  type PurchaseBillSupplierRef,
  type PurchaseBillWritePayload,
} from "./types";

export class PurchaseBillDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "PurchaseBillDataError";
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

function asStatus(value: unknown): PurchaseBillStatus | undefined {
  return PURCHASE_BILL_STATUS_VALUES.includes(value as PurchaseBillStatus)
    ? (value as PurchaseBillStatus)
    : undefined;
}

function parseSupplierRef(value: unknown): PurchaseBillSupplierRef | null | undefined {
  if (value === null || value === undefined) {
    return null;
  }
  if (!isRecord(value)) {
    return undefined;
  }
  const id = isNumber(value.id) ? value.id : undefined;
  const supplier_code = typeof value.supplier_code === "string" ? value.supplier_code : undefined;
  const name = typeof value.name === "string" ? value.name : undefined;
  if (id === undefined || supplier_code === undefined || name === undefined) {
    return undefined;
  }
  return { id, supplier_code, name };
}

export function parsePurchaseBillLine(value: unknown): PurchaseBillLine | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const line_number = isNumber(value.line_number) ? value.line_number : undefined;
  const quantity = isDecimalString(value.quantity) ? value.quantity : undefined;
  const unit_cost = isDecimalString(value.unit_cost) ? value.unit_cost : undefined;
  const discount = isDecimalString(value.discount) ? value.discount : undefined;
  const tax_rate = isDecimalString(value.tax_rate) ? value.tax_rate : undefined;
  const line_total = isDecimalString(value.line_total) ? value.line_total : undefined;
  const tax_amount = isDecimalString(value.tax_amount) ? value.tax_amount : undefined;
  const expense_account_id = isNumber(value.expense_account_id) ? value.expense_account_id : undefined;

  if (
    id === undefined ||
    line_number === undefined ||
    quantity === undefined ||
    unit_cost === undefined ||
    discount === undefined ||
    tax_rate === undefined ||
    line_total === undefined ||
    tax_amount === undefined ||
    expense_account_id === undefined
  ) {
    return null;
  }

  return {
    id,
    line_number,
    description: asNullableString(value.description),
    quantity,
    unit_cost,
    discount,
    tax_rate,
    line_total,
    tax_amount,
    expense_account_id,
  };
}

export function parsePurchaseBill(value: unknown): PurchaseBill | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const bill_number = typeof value.bill_number === "string" ? value.bill_number : undefined;
  const supplier_id = isNumber(value.supplier_id) ? value.supplier_id : undefined;
  const bill_date = isDateOnly(value.bill_date) ? value.bill_date : undefined;
  const due_date = isDateOnly(value.due_date) ? value.due_date : undefined;
  const status = asStatus(value.status);
  const subtotal = isDecimalString(value.subtotal) ? value.subtotal : undefined;
  const discount_total = isDecimalString(value.discount_total) ? value.discount_total : undefined;
  const tax_total = isDecimalString(value.tax_total) ? value.tax_total : undefined;
  const grand_total = isDecimalString(value.grand_total) ? value.grand_total : undefined;

  if (
    id === undefined ||
    bill_number === undefined ||
    supplier_id === undefined ||
    bill_date === undefined ||
    due_date === undefined ||
    status === undefined ||
    subtotal === undefined ||
    discount_total === undefined ||
    tax_total === undefined ||
    grand_total === undefined
  ) {
    return null;
  }

  const supplier = parseSupplierRef(value.supplier);
  if (supplier === undefined) {
    return null;
  }

  const lines: PurchaseBillLine[] = [];
  if (Array.isArray(value.lines)) {
    for (const entry of value.lines) {
      const line = parsePurchaseBillLine(entry);
      if (line !== null) {
        lines.push(line);
      }
    }
  }

  return {
    id,
    bill_number,
    supplier_id,
    bill_date,
    due_date,
    status,
    subtotal,
    discount_total,
    tax_total,
    grand_total,
    paid_total: isDecimalString(value.paid_total) ? value.paid_total : value.paid_total === null ? null : undefined,
    balance_due: isDecimalString(value.balance_due) ? value.balance_due : value.balance_due === null ? null : undefined,
    is_overdue: typeof value.is_overdue === "boolean" ? value.is_overdue : value.is_overdue === null ? null : undefined,
    days_overdue: isNumber(value.days_overdue) ? value.days_overdue : value.days_overdue === null ? null : undefined,
    tax_account_id: isNumber(value.tax_account_id) ? value.tax_account_id : null,
    journal_id: isNumber(value.journal_id) ? value.journal_id : null,
    posted_at: asNullableString(value.posted_at),
    notes: asNullableString(value.notes),
    lines,
    supplier,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toErrorState(message: string): PurchaseBillListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

export function parsePurchaseBillList(raw: unknown, perPage: number): PurchaseBillListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toErrorState("The purchase bills response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: PurchaseBill[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const bill = parsePurchaseBill(entry);
    if (bill === null) {
      skipped += 1;
      continue;
    }
    items.push(bill);
  }

  if (skipped > 0 && items.length === 0) {
    return toErrorState("The purchase bills response contained no valid rows.");
  }

  return { status: "ready", items, hasMore: list.hasMore, perPage };
}

export function parsePurchaseBillOrThrow(raw: unknown, label = "purchase bill"): PurchaseBill {
  const bill = parsePurchaseBill(raw);
  if (bill === null) {
    throw new PurchaseBillDataError(`The ${label} response was invalid.`);
  }
  return bill;
}

export type PurchaseBillValidationErrors = Record<string, string>;

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const DECIMAL_PATTERN = /^\d+(?:\.\d{1,10})?$/;

function splitDecimal(value: string): { int: string; frac: string } {
  const [int, frac = ""] = value.split(".");
  return { int: int || "0", frac };
}

/** Exact multiplication of two non-negative decimal strings. */
export function multiplyDecimals(a: string, b: string): string {
  const da = splitDecimal(a);
  const db = splitDecimal(b);
  const product = BigInt(da.int + da.frac) * BigInt(db.int + db.frac);
  const scale = da.frac.length + db.frac.length;
  const digits = product.toString().padStart(scale + 1, "0");
  if (scale === 0) {
    return digits;
  }
  return `${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
}

/** -1, 0 or 1 comparing two non-negative decimal strings exactly. */
export function compareDecimals(a: string, b: string): number {
  const da = splitDecimal(a);
  const db = splitDecimal(b);
  const scale = Math.max(da.frac.length, db.frac.length);
  const va = BigInt(da.int + da.frac.padEnd(scale, "0"));
  const vb = BigInt(db.int + db.frac.padEnd(scale, "0"));
  return va === vb ? 0 : va < vb ? -1 : 1;
}

function isPositive(value: string): boolean {
  return DECIMAL_PATTERN.test(value) && !/^0+(?:\.0+)?$/.test(value);
}

/** Mirrors the verified `StorePurchaseBillRequest` rules and line guards. */
export function validatePurchaseBillWrite(
  payload: PurchaseBillWritePayload,
): PurchaseBillValidationErrors {
  const errors: PurchaseBillValidationErrors = {};

  if (
    payload.supplier_id === null ||
    !Number.isInteger(payload.supplier_id) ||
    payload.supplier_id <= 0
  ) {
    errors.supplier_id = "Select a supplier.";
  }

  if (!DATE_PATTERN.test(payload.bill_date)) {
    errors.bill_date = "The bill date must be in YYYY-MM-DD format.";
  }

  if (!DATE_PATTERN.test(payload.due_date)) {
    errors.due_date = "The due date must be in YYYY-MM-DD format.";
  } else if (DATE_PATTERN.test(payload.bill_date) && payload.due_date < payload.bill_date) {
    errors.due_date = "The due date must be on or after the bill date.";
  }

  if (payload.lines.length === 0) {
    errors.lines = "Add at least one bill line.";
  }
  if (payload.lines.length > 500) {
    errors.lines = "A bill may not have more than 500 lines.";
  }

  payload.lines.forEach((line, index) => {
    validateLine(line, index, errors);
  });

  return errors;
}

function validateLine(
  line: PurchaseBillLinePayload,
  index: number,
  errors: PurchaseBillValidationErrors,
): void {
  const prefix = `lines.${index}.`;

  if (line.quantity.trim() === "") {
    errors[`${prefix}quantity`] = "The quantity is required.";
  } else if (!isPositive(line.quantity)) {
    errors[`${prefix}quantity`] = "The quantity must be a positive number.";
  }

  if (line.unit_cost.trim() === "") {
    errors[`${prefix}unit_cost`] = "The unit cost is required.";
  } else if (!DECIMAL_PATTERN.test(line.unit_cost)) {
    errors[`${prefix}unit_cost`] = "The unit cost must not be negative.";
  }

  if (line.discount.trim() === "") {
    errors[`${prefix}discount`] = "The discount is required.";
  } else if (!DECIMAL_PATTERN.test(line.discount)) {
    errors[`${prefix}discount`] = "The discount must not be negative.";
  }

  if (line.tax_rate.trim() === "") {
    errors[`${prefix}tax_rate`] = "The tax rate is required.";
  } else if (!DECIMAL_PATTERN.test(line.tax_rate)) {
    errors[`${prefix}tax_rate`] = "The tax rate must not be negative.";
  }

  if (
    line.expense_account_id === null ||
    !Number.isInteger(line.expense_account_id) ||
    line.expense_account_id <= 0
  ) {
    errors[`${prefix}expense_account_id`] = "Select an expense account.";
  }

  if (
    isPositive(line.quantity) &&
    DECIMAL_PATTERN.test(line.unit_cost) &&
    DECIMAL_PATTERN.test(line.discount)
  ) {
    const gross = multiplyDecimals(line.quantity, line.unit_cost);
    if (compareDecimals(line.discount, gross) > 0) {
      errors[`${prefix}discount`] = "The discount may not exceed the line gross amount.";
    }
  }

  if (line.description !== null && line.description.length > 1000) {
    errors[`${prefix}description`] = "The description may not be longer than 1000 characters.";
  }
}
