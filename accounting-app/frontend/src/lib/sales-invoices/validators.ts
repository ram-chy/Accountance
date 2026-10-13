/**
 * Runtime validation for sales invoice responses and write payloads.
 *
 * Response parsing narrows one row to the exact fields the UI renders. A row
 * missing a required identifier or carrying an unknown status is dropped rather
 * than fabricated. Write validation mirrors `StoreSalesInvoiceRequest` (and the
 * line guards in `DocumentCalculator`) for immediate feedback; the backend is
 * still the validator of record and owns every total.
 */

import { normalizeList } from "@/lib/api/pagination";
import { isDateOnly } from "@/lib/format/date";
import { isDecimalString } from "@/lib/format/money";

import {
  SALES_INVOICE_STATUS_VALUES,
  type SalesInvoice,
  type SalesInvoiceCustomerRef,
  type SalesInvoiceLine,
  type SalesInvoiceLinePayload,
  type SalesInvoiceListState,
  type SalesInvoiceStatus,
  type SalesInvoiceWritePayload,
} from "./types";

export class SalesInvoiceDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "SalesInvoiceDataError";
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

function asStatus(value: unknown): SalesInvoiceStatus | undefined {
  return SALES_INVOICE_STATUS_VALUES.includes(value as SalesInvoiceStatus)
    ? (value as SalesInvoiceStatus)
    : undefined;
}

function parseCustomerRef(value: unknown): SalesInvoiceCustomerRef | null | undefined {
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

export function parseSalesInvoiceLine(value: unknown): SalesInvoiceLine | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const line_number = isNumber(value.line_number) ? value.line_number : undefined;
  const quantity = isDecimalString(value.quantity) ? value.quantity : undefined;
  const unit_price = isDecimalString(value.unit_price) ? value.unit_price : undefined;
  const discount = isDecimalString(value.discount) ? value.discount : undefined;
  const tax_rate = isDecimalString(value.tax_rate) ? value.tax_rate : undefined;
  const line_total = isDecimalString(value.line_total) ? value.line_total : undefined;
  const tax_amount = isDecimalString(value.tax_amount) ? value.tax_amount : undefined;
  const revenue_account_id = isNumber(value.revenue_account_id) ? value.revenue_account_id : undefined;

  if (
    id === undefined ||
    line_number === undefined ||
    quantity === undefined ||
    unit_price === undefined ||
    discount === undefined ||
    tax_rate === undefined ||
    line_total === undefined ||
    tax_amount === undefined ||
    revenue_account_id === undefined
  ) {
    return null;
  }

  return {
    id,
    line_number,
    description: asNullableString(value.description),
    quantity,
    unit_price,
    discount,
    tax_rate,
    line_total,
    tax_amount,
    revenue_account_id,
  };
}

export function parseSalesInvoice(value: unknown): SalesInvoice | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const invoice_number = typeof value.invoice_number === "string" ? value.invoice_number : undefined;
  const customer_id = isNumber(value.customer_id) ? value.customer_id : undefined;
  const invoice_date = isDateOnly(value.invoice_date) ? value.invoice_date : undefined;
  const due_date = isDateOnly(value.due_date) ? value.due_date : undefined;
  const status = asStatus(value.status);
  const subtotal = isDecimalString(value.subtotal) ? value.subtotal : undefined;
  const discount_total = isDecimalString(value.discount_total) ? value.discount_total : undefined;
  const tax_total = isDecimalString(value.tax_total) ? value.tax_total : undefined;
  const grand_total = isDecimalString(value.grand_total) ? value.grand_total : undefined;

  if (
    id === undefined ||
    invoice_number === undefined ||
    customer_id === undefined ||
    invoice_date === undefined ||
    due_date === undefined ||
    status === undefined ||
    subtotal === undefined ||
    discount_total === undefined ||
    tax_total === undefined ||
    grand_total === undefined
  ) {
    return null;
  }

  const customer = parseCustomerRef(value.customer);
  if (customer === undefined) {
    return null;
  }

  const lines: SalesInvoiceLine[] = [];
  if (Array.isArray(value.lines)) {
    for (const entry of value.lines) {
      const line = parseSalesInvoiceLine(entry);
      if (line !== null) {
        lines.push(line);
      }
    }
  }

  return {
    id,
    invoice_number,
    customer_id,
    invoice_date,
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
    customer,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toErrorState(message: string): SalesInvoiceListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

export function parseSalesInvoiceList(raw: unknown, perPage: number): SalesInvoiceListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toErrorState("The sales invoices response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: SalesInvoice[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const invoice = parseSalesInvoice(entry);
    if (invoice === null) {
      skipped += 1;
      continue;
    }
    items.push(invoice);
  }

  if (skipped > 0 && items.length === 0) {
    return toErrorState("The sales invoices response contained no valid rows.");
  }

  return { status: "ready", items, hasMore: list.hasMore, perPage };
}

export function parseSalesInvoiceOrThrow(raw: unknown, label = "sales invoice"): SalesInvoice {
  const invoice = parseSalesInvoice(raw);
  if (invoice === null) {
    throw new SalesInvoiceDataError(`The ${label} response was invalid.`);
  }
  return invoice;
}

export type SalesInvoiceValidationErrors = Record<string, string>;

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

/** Mirrors the verified `StoreSalesInvoiceRequest` rules and line guards. */
export function validateSalesInvoiceWrite(
  payload: SalesInvoiceWritePayload,
): SalesInvoiceValidationErrors {
  const errors: SalesInvoiceValidationErrors = {};

  if (
    payload.customer_id === null ||
    !Number.isInteger(payload.customer_id) ||
    payload.customer_id <= 0
  ) {
    errors.customer_id = "Select a customer.";
  }

  if (!DATE_PATTERN.test(payload.invoice_date)) {
    errors.invoice_date = "The invoice date must be in YYYY-MM-DD format.";
  }

  if (!DATE_PATTERN.test(payload.due_date)) {
    errors.due_date = "The due date must be in YYYY-MM-DD format.";
  } else if (DATE_PATTERN.test(payload.invoice_date) && payload.due_date < payload.invoice_date) {
    errors.due_date = "The due date must be on or after the invoice date.";
  }

  if (payload.lines.length === 0) {
    errors.lines = "Add at least one invoice line.";
  }
  if (payload.lines.length > 500) {
    errors.lines = "An invoice may not have more than 500 lines.";
  }

  payload.lines.forEach((line, index) => {
    validateLine(line, index, errors);
  });

  return errors;
}

function validateLine(
  line: SalesInvoiceLinePayload,
  index: number,
  errors: SalesInvoiceValidationErrors,
): void {
  const prefix = `lines.${index}.`;

  if (line.quantity.trim() === "") {
    errors[`${prefix}quantity`] = "The quantity is required.";
  } else if (!isPositive(line.quantity)) {
    errors[`${prefix}quantity`] = "The quantity must be a positive number.";
  }

  if (line.unit_price.trim() === "") {
    errors[`${prefix}unit_price`] = "The unit price is required.";
  } else if (!DECIMAL_PATTERN.test(line.unit_price)) {
    errors[`${prefix}unit_price`] = "The unit price must not be negative.";
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

  if (line.revenue_account_id === null || !Number.isInteger(line.revenue_account_id) || line.revenue_account_id <= 0) {
    errors[`${prefix}revenue_account_id`] = "Select a revenue account.";
  }

  if (
    isPositive(line.quantity) &&
    DECIMAL_PATTERN.test(line.unit_price) &&
    DECIMAL_PATTERN.test(line.discount)
  ) {
    const gross = multiplyDecimals(line.quantity, line.unit_price);
    if (compareDecimals(line.discount, gross) > 0) {
      errors[`${prefix}discount`] = "The discount may not exceed the line gross amount.";
    }
  }

  if (line.description !== null && line.description.length > 1000) {
    errors[`${prefix}description`] = "The description may not be longer than 1000 characters.";
  }
}
