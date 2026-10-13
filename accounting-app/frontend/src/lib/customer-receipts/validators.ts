/**
 * Runtime validation for customer receipt responses and write payloads.
 *
 * Response parsing narrows one row to the fields the UI renders. A row missing
 * a required identifier or carrying an unknown status is dropped. Write
 * validation mirrors `Store/UpdateCustomerReceiptRequest`, including the
 * cross-field rule that allocations must total exactly the receipt amount (the
 * backend has no customer-advance mechanism).
 */

import { normalizeList } from "@/lib/api/pagination";
import { isDateOnly } from "@/lib/format/date";
import { isDecimalString } from "@/lib/format/money";

import {
  CUSTOMER_RECEIPT_STATUS_VALUES,
  type CustomerReceipt,
  type CustomerReceiptAllocation,
  type CustomerReceiptCustomerRef,
  type CustomerReceiptListState,
  type CustomerReceiptStatus,
  type CustomerReceiptWritePayload,
  type ReceiptInvoiceRef,
} from "./types";

export class CustomerReceiptDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "CustomerReceiptDataError";
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

function asStatus(value: unknown): CustomerReceiptStatus | undefined {
  return CUSTOMER_RECEIPT_STATUS_VALUES.includes(value as CustomerReceiptStatus)
    ? (value as CustomerReceiptStatus)
    : undefined;
}

function parseInvoiceRef(value: unknown): ReceiptInvoiceRef | null | undefined {
  if (value === null || value === undefined) {
    return null;
  }
  if (!isRecord(value)) {
    return undefined;
  }
  const id = isNumber(value.id) ? value.id : undefined;
  const invoice_number = typeof value.invoice_number === "string" ? value.invoice_number : undefined;
  if (id === undefined || invoice_number === undefined) {
    return undefined;
  }
  return {
    id,
    invoice_number,
    status: typeof value.status === "string" ? value.status : undefined,
    grand_total: isDecimalString(value.grand_total) ? value.grand_total : undefined,
    balance_due: isDecimalString(value.balance_due) ? value.balance_due : null,
  };
}

function parseAllocation(value: unknown): CustomerReceiptAllocation | null {
  if (!isRecord(value)) {
    return null;
  }
  const id = isNumber(value.id) ? value.id : undefined;
  const customer_receipt_id = isNumber(value.customer_receipt_id) ? value.customer_receipt_id : undefined;
  const sales_invoice_id = isNumber(value.sales_invoice_id) ? value.sales_invoice_id : undefined;
  const amount = isDecimalString(value.amount) ? value.amount : undefined;

  if (
    id === undefined ||
    customer_receipt_id === undefined ||
    sales_invoice_id === undefined ||
    amount === undefined
  ) {
    return null;
  }

  const sales_invoice = parseInvoiceRef(value.sales_invoice);
  if (sales_invoice === undefined) {
    return null;
  }

  return { id, customer_receipt_id, sales_invoice_id, amount, sales_invoice };
}

function parseCustomerRef(value: unknown): CustomerReceiptCustomerRef | null | undefined {
  if (value === null || value === undefined) {
    return null;
  }
  if (!isRecord(value)) {
    return undefined;
  }
  const id = isNumber(value.id) ? value.id : undefined;
  const customer_code = typeof value.customer_code === "string" ? value.customer_code : undefined;
  const name = typeof value.name === "string" ? value.name : undefined;
  if (id === undefined || customer_code === undefined || name === undefined) {
    return undefined;
  }
  return { id, customer_code, name };
}

export function parseCustomerReceipt(value: unknown): CustomerReceipt | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const receipt_number = typeof value.receipt_number === "string" ? value.receipt_number : undefined;
  const customer_id = isNumber(value.customer_id) ? value.customer_id : undefined;
  const receipt_date = isDateOnly(value.receipt_date) ? value.receipt_date : undefined;
  const amount = isDecimalString(value.amount) ? value.amount : undefined;
  const status = asStatus(value.status);
  const payment_account_id = isNumber(value.payment_account_id) ? value.payment_account_id : undefined;

  if (
    id === undefined ||
    receipt_number === undefined ||
    customer_id === undefined ||
    receipt_date === undefined ||
    amount === undefined ||
    status === undefined ||
    payment_account_id === undefined
  ) {
    return null;
  }

  const customer = parseCustomerRef(value.customer);
  if (customer === undefined) {
    return null;
  }

  const allocations: CustomerReceiptAllocation[] = [];
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
    receipt_number,
    customer_id,
    receipt_date,
    amount,
    status,
    payment_account_id,
    reference: asNullableString(value.reference),
    notes: asNullableString(value.notes),
    allocations,
    customer,
    journal_id: isNumber(value.journal_id) ? value.journal_id : null,
    posted_at: asNullableString(value.posted_at),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toErrorState(message: string): CustomerReceiptListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

export function parseCustomerReceiptList(raw: unknown, perPage: number): CustomerReceiptListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toErrorState("The customer receipts response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: CustomerReceipt[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const receipt = parseCustomerReceipt(entry);
    if (receipt === null) {
      skipped += 1;
      continue;
    }
    items.push(receipt);
  }

  if (skipped > 0 && items.length === 0) {
    return toErrorState("The customer receipts response contained no valid rows.");
  }

  return { status: "ready", items, hasMore: list.hasMore, perPage };
}

export function parseCustomerReceiptOrThrow(raw: unknown, label = "customer receipt"): CustomerReceipt {
  const receipt = parseCustomerReceipt(raw);
  if (receipt === null) {
    throw new CustomerReceiptDataError(`The ${label} response was invalid.`);
  }
  return receipt;
}

export type CustomerReceiptValidationErrors = Record<string, string>;

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

/** Mirrors `StoreCustomerReceiptRequest` including the cross-field sum rule. */
export function validateCustomerReceiptWrite(
  payload: CustomerReceiptWritePayload,
): CustomerReceiptValidationErrors {
  const errors: CustomerReceiptValidationErrors = {};

  if (payload.customer_id === null || !Number.isInteger(payload.customer_id) || payload.customer_id <= 0) {
    errors.customer_id = "Select a customer.";
  }

  if (!DATE_PATTERN.test(payload.receipt_date)) {
    errors.receipt_date = "The receipt date must be in YYYY-MM-DD format.";
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
    errors.allocations = "Allocate the receipt to at least one invoice.";
  }

  const seen = new Set<number>();
  let sum = "0";

  payload.allocations.forEach((allocation, index) => {
    const prefix = `allocations.${index}.`;

    if (
      !Number.isInteger(allocation.sales_invoice_id) ||
      allocation.sales_invoice_id <= 0
    ) {
      errors[`${prefix}sales_invoice_id`] = "Select an invoice.";
    } else if (seen.has(allocation.sales_invoice_id)) {
      errors[`${prefix}sales_invoice_id`] = "An invoice may only be allocated once.";
    } else {
      seen.add(allocation.sales_invoice_id);
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
    errors.allocations = "Allocations must total exactly the receipt amount.";
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
