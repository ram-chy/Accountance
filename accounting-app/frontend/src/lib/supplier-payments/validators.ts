/**
 * Runtime validation for supplier payment responses and write payloads.
 *
 * Response parsing narrows one row to the fields the UI renders. A row missing
 * a required identifier or carrying an unknown status is dropped. Write
 * validation mirrors `Store/UpdateSupplierPaymentRequest`, including the
 * cross-field rule that allocations must total exactly the payment amount (the
 * backend has no supplier-advance mechanism).
 */

import { normalizeList } from "@/lib/api/pagination";
import { isDateOnly } from "@/lib/format/date";
import { isDecimalString } from "@/lib/format/money";

import {
  SUPPLIER_PAYMENT_STATUS_VALUES,
  type PaymentBillRef,
  type SupplierPayment,
  type SupplierPaymentAllocation,
  type SupplierPaymentListState,
  type SupplierPaymentStatus,
  type SupplierPaymentSupplierRef,
  type SupplierPaymentWritePayload,
} from "./types";

export class SupplierPaymentDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "SupplierPaymentDataError";
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

function asStatus(value: unknown): SupplierPaymentStatus | undefined {
  return SUPPLIER_PAYMENT_STATUS_VALUES.includes(value as SupplierPaymentStatus)
    ? (value as SupplierPaymentStatus)
    : undefined;
}

function parseBillRef(value: unknown): PaymentBillRef | null | undefined {
  if (value === null || value === undefined) {
    return null;
  }
  if (!isRecord(value)) {
    return undefined;
  }
  const id = isNumber(value.id) ? value.id : undefined;
  const bill_number = typeof value.bill_number === "string" ? value.bill_number : undefined;
  if (id === undefined || bill_number === undefined) {
    return undefined;
  }
  return {
    id,
    bill_number,
    status: typeof value.status === "string" ? value.status : undefined,
    grand_total: isDecimalString(value.grand_total) ? value.grand_total : undefined,
    balance_due: isDecimalString(value.balance_due) ? value.balance_due : null,
  };
}

function parseAllocation(value: unknown): SupplierPaymentAllocation | null {
  if (!isRecord(value)) {
    return null;
  }
  const id = isNumber(value.id) ? value.id : undefined;
  const supplier_payment_id = isNumber(value.supplier_payment_id) ? value.supplier_payment_id : undefined;
  const purchase_bill_id = isNumber(value.purchase_bill_id) ? value.purchase_bill_id : undefined;
  const amount = isDecimalString(value.amount) ? value.amount : undefined;

  if (
    id === undefined ||
    supplier_payment_id === undefined ||
    purchase_bill_id === undefined ||
    amount === undefined
  ) {
    return null;
  }

  const purchase_bill = parseBillRef(value.purchase_bill);
  if (purchase_bill === undefined) {
    return null;
  }

  return { id, supplier_payment_id, purchase_bill_id, amount, purchase_bill };
}

function parseSupplierRef(value: unknown): SupplierPaymentSupplierRef | null | undefined {
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

export function parseSupplierPayment(value: unknown): SupplierPayment | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const payment_number = typeof value.payment_number === "string" ? value.payment_number : undefined;
  const supplier_id = isNumber(value.supplier_id) ? value.supplier_id : undefined;
  const payment_date = isDateOnly(value.payment_date) ? value.payment_date : undefined;
  const amount = isDecimalString(value.amount) ? value.amount : undefined;
  const status = asStatus(value.status);
  const payment_account_id = isNumber(value.payment_account_id) ? value.payment_account_id : undefined;

  if (
    id === undefined ||
    payment_number === undefined ||
    supplier_id === undefined ||
    payment_date === undefined ||
    amount === undefined ||
    status === undefined ||
    payment_account_id === undefined
  ) {
    return null;
  }

  const supplier = parseSupplierRef(value.supplier);
  if (supplier === undefined) {
    return null;
  }

  const allocations: SupplierPaymentAllocation[] = [];
  if (Array.isArray(value.allocations)) {
    for (const entry of value.allocations) {
      const allocation = parseAllocation(entry);
      if (allocation !== null) {
        allocations.push(allocation);
      }
    }
  }

  return {
    id,
    payment_number,
    supplier_id,
    payment_date,
    amount,
    status,
    payment_account_id,
    reference: asNullableString(value.reference),
    notes: asNullableString(value.notes),
    allocations,
    supplier,
    journal_id: isNumber(value.journal_id) ? value.journal_id : null,
    posted_at: asNullableString(value.posted_at),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toErrorState(message: string): SupplierPaymentListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

export function parseSupplierPaymentList(raw: unknown, perPage: number): SupplierPaymentListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toErrorState("The supplier payments response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: SupplierPayment[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const payment = parseSupplierPayment(entry);
    if (payment === null) {
      skipped += 1;
      continue;
    }
    items.push(payment);
  }

  if (skipped > 0 && items.length === 0) {
    return toErrorState("The supplier payments response contained no valid rows.");
  }

  return { status: "ready", items, hasMore: list.hasMore, perPage };
}

export function parseSupplierPaymentOrThrow(raw: unknown, label = "supplier payment"): SupplierPayment {
  const payment = parseSupplierPayment(raw);
  if (payment === null) {
    throw new SupplierPaymentDataError(`The ${label} response was invalid.`);
  }
  return payment;
}

export type SupplierPaymentValidationErrors = Record<string, string>;

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const POSITIVE_PATTERN = /^\d+(?:\.\d{1,10})?$/;

function isPositive(value: string): boolean {
  return POSITIVE_PATTERN.test(value) && !/^0+(?:\.0+)?$/.test(value);
}

function splitDecimal(value: string): { int: string; frac: string } {
  const [int, frac = ""] = value.split(".");
  return { int: int || "0", frac };
}

function scaled(value: string, scale: number): bigint {
  const { int, frac } = splitDecimal(value);
  return BigInt(int + frac.padEnd(scale, "0").slice(0, scale) || "0");
}

/** Exact equality of two decimal strings. */
export function decimalsEqual(a: string, b: string): boolean {
  const scale = Math.max(splitDecimal(a).frac.length, splitDecimal(b).frac.length);
  return scaled(a, scale) === scaled(b, scale);
}

/** Mirrors `StoreSupplierPaymentRequest` including the cross-field sum rule. */
export function validateSupplierPaymentWrite(
  payload: SupplierPaymentWritePayload,
): SupplierPaymentValidationErrors {
  const errors: SupplierPaymentValidationErrors = {};

  if (payload.supplier_id === null || !Number.isInteger(payload.supplier_id) || payload.supplier_id <= 0) {
    errors.supplier_id = "Select a supplier.";
  }

  if (!DATE_PATTERN.test(payload.payment_date)) {
    errors.payment_date = "The payment date must be in YYYY-MM-DD format.";
  }

  const amount = payload.amount.trim();
  if (amount === "") {
    errors.amount = "The amount is required.";
  } else if (!POSITIVE_PATTERN.test(amount)) {
    errors.amount = "The amount must be a positive number with at most 10 decimal places.";
  } else if (!isPositive(amount)) {
    errors.amount = "The amount must be greater than zero.";
  }

  if (
    payload.payment_account_id === null ||
    !Number.isInteger(payload.payment_account_id) ||
    payload.payment_account_id <= 0
  ) {
    errors.payment_account_id = "Select a payment account.";
  }

  if (payload.allocations.length === 0) {
    errors.allocations = "Allocate the payment to at least one bill.";
  }

  const seen = new Set<number>();
  let sum = "0";

  payload.allocations.forEach((allocation, index) => {
    const prefix = `allocations.${index}.`;

    if (
      !Number.isInteger(allocation.purchase_bill_id) ||
      allocation.purchase_bill_id <= 0
    ) {
      errors[`${prefix}purchase_bill_id`] = "Select a bill.";
    } else if (seen.has(allocation.purchase_bill_id)) {
      errors[`${prefix}purchase_bill_id`] = "A bill may only be allocated once.";
    } else {
      seen.add(allocation.purchase_bill_id);
    }

    const allocAmount = allocation.amount.trim();
    if (!POSITIVE_PATTERN.test(allocAmount) || !isPositive(allocAmount)) {
      errors[`${prefix}amount`] = "Enter a positive amount.";
      return;
    }

    sum = sumDecimals(sum, allocAmount);
  });

  if (
    POSITIVE_PATTERN.test(amount) &&
    isPositive(amount) &&
    payload.allocations.length > 0 &&
    !decimalsEqual(sum, amount)
  ) {
    errors.allocations = "Allocations must total exactly the payment amount.";
  }

  if (payload.reference !== null && payload.reference.length > 255) {
    errors.reference = "The reference may not be longer than 255 characters.";
  }

  if (payload.notes !== null && payload.notes.length > 5000) {
    errors.notes = "The notes may not be longer than 5000 characters.";
  }

  return errors;
}

function sumDecimals(a: string, b: string): string {
  const scale = Math.max(splitDecimal(a).frac.length, splitDecimal(b).frac.length);
  const total = scaled(a, scale) + scaled(b, scale);
  const digits = total.toString().padStart(scale + 1, "0");
  if (scale === 0) return digits;
  return `${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
}
