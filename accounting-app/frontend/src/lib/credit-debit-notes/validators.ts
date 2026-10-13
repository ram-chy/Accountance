/**
 * Runtime validation for credit / debit note responses and write payloads.
 *
 * Response parsing narrows a row to the fields the UI renders and drops a row
 * that is missing a required identifier or carrying an unknown enum value,
 * rather than fabricating one. Write validation mirrors
 * `Store/UpdateCreditDebitNoteRequest` for immediate feedback; the backend is
 * the validator of record and owns every total.
 */

import { normalizeList } from "@/lib/api/pagination";
import { isDateOnly } from "@/lib/format/date";
import { isDecimalString } from "@/lib/format/money";

import {
  NOTE_STATUS_VALUES,
  NOTE_TYPE_VALUES,
  type AdjustableLine,
  type AdjustableSource,
  type CreditDebitNote,
  type CreditDebitNoteLine,
  type CreditDebitNoteLinePayload,
  type CreditDebitNoteListState,
  type CreditDebitNoteWritePayload,
  type NoteCounterpartyRef,
  type NoteDocumentType,
  type NoteStatus,
  type NoteType,
} from "./types";

export class CreditDebitNoteDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "CreditDebitNoteDataError";
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

function asNullableNumber(value: unknown): number | null {
  return isNumber(value) ? value : null;
}

export function asNoteType(value: unknown): NoteType | undefined {
  return NOTE_TYPE_VALUES.includes(value as NoteType) ? (value as NoteType) : undefined;
}

function asNoteStatus(value: unknown): NoteStatus | undefined {
  return NOTE_STATUS_VALUES.includes(value as NoteStatus) ? (value as NoteStatus) : undefined;
}

function asDocumentType(value: unknown): NoteDocumentType | null | undefined {
  if (value === null) return null;
  if (value === "sales_invoice" || value === "purchase_bill") return value;
  return undefined;
}

function parseCounterpartyRef(value: unknown): NoteCounterpartyRef | null | undefined {
  if (value === null || value === undefined) {
    return null;
  }
  if (!isRecord(value)) {
    return undefined;
  }
  const id = isNumber(value.id) ? value.id : undefined;
  const codeValue = value.customer_code ?? value.supplier_code;
  const code = typeof codeValue === "string" ? codeValue : undefined;
  const name = typeof value.name === "string" ? value.name : undefined;
  if (id === undefined || code === undefined || name === undefined) {
    return undefined;
  }
  return { id, code, name };
}

export function parseCreditDebitNoteLine(value: unknown): CreditDebitNoteLine | null {
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
  const account_id = isNumber(value.account_id) ? value.account_id : undefined;
  const source_document_type = asDocumentType(value.source_document_type ?? null);

  if (
    id === undefined ||
    line_number === undefined ||
    quantity === undefined ||
    unit_price === undefined ||
    discount === undefined ||
    tax_rate === undefined ||
    line_total === undefined ||
    tax_amount === undefined ||
    account_id === undefined ||
    source_document_type === undefined
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
    tax_id: asNullableNumber(value.tax_id),
    account_id,
    source_document_type,
    source_line_id: asNullableNumber(value.source_line_id),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

export function parseCreditDebitNote(value: unknown): CreditDebitNote | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = isNumber(value.id) ? value.id : undefined;
  const note_number = typeof value.note_number === "string" ? value.note_number : undefined;
  const note_type = asNoteType(value.note_type);
  const label = typeof value.label === "string" ? value.label : undefined;
  const note_date = isDateOnly(value.note_date) ? value.note_date : undefined;
  const status = asNoteStatus(value.status);
  const subtotal = isDecimalString(value.subtotal) ? value.subtotal : undefined;
  const discount_total = isDecimalString(value.discount_total) ? value.discount_total : undefined;
  const tax_total = isDecimalString(value.tax_total) ? value.tax_total : undefined;
  const grand_total = isDecimalString(value.grand_total) ? value.grand_total : undefined;
  const reason = typeof value.reason === "string" ? value.reason : undefined;
  const source_document_type = asDocumentType(value.source_document_type);
  const source_document_id = isNumber(value.source_document_id) ? value.source_document_id : undefined;

  if (
    id === undefined ||
    note_number === undefined ||
    note_type === undefined ||
    label === undefined ||
    note_date === undefined ||
    status === undefined ||
    subtotal === undefined ||
    discount_total === undefined ||
    tax_total === undefined ||
    grand_total === undefined ||
    reason === undefined ||
    source_document_type === null ||
    source_document_type === undefined ||
    source_document_id === undefined
  ) {
    return null;
  }

  const customer = parseCounterpartyRef(value.customer);
  if (customer === undefined) {
    return null;
  }
  const supplier = parseCounterpartyRef(value.supplier);
  if (supplier === undefined) {
    return null;
  }

  const lines: CreditDebitNoteLine[] = [];
  if (Array.isArray(value.lines)) {
    for (const entry of value.lines) {
      const line = parseCreditDebitNoteLine(entry);
      if (line !== null) {
        lines.push(line);
      }
    }
  }

  return {
    id,
    note_number,
    note_type,
    is_credit: typeof value.is_credit === "boolean" ? value.is_credit : note_type.includes("CREDIT"),
    label,
    note_date,
    status,
    subtotal,
    discount_total,
    tax_total,
    grand_total,
    reason,
    reference: asNullableString(value.reference),
    notes: asNullableString(value.notes),
    source_document_type,
    source_document_id,
    source_document_number: asNullableString(value.source_document_number),
    sales_invoice_id: asNullableNumber(value.sales_invoice_id),
    purchase_bill_id: asNullableNumber(value.purchase_bill_id),
    customer_id: asNullableNumber(value.customer_id),
    supplier_id: asNullableNumber(value.supplier_id),
    customer,
    supplier,
    tax_account_id: asNullableNumber(value.tax_account_id),
    journal_id: asNullableNumber(value.journal_id),
    posted_at: asNullableString(value.posted_at),
    lines,
    created_by: asNullableNumber(value.created_by),
    posted_by: asNullableNumber(value.posted_by),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toErrorState(message: string): CreditDebitNoteListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

export function parseCreditDebitNoteList(raw: unknown, perPage: number): CreditDebitNoteListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toErrorState("The credit and debit notes response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: CreditDebitNote[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const note = parseCreditDebitNote(entry);
    if (note === null) {
      skipped += 1;
      continue;
    }
    items.push(note);
  }

  if (skipped > 0 && items.length === 0) {
    return toErrorState("The credit and debit notes response contained no valid rows.");
  }

  return { status: "ready", items, hasMore: list.hasMore, perPage };
}

export function parseCreditDebitNoteOrThrow(
  raw: unknown,
  label = "credit or debit note",
): CreditDebitNote {
  const note = parseCreditDebitNote(raw);
  if (note === null) {
    throw new CreditDebitNoteDataError(`The ${label} response was invalid.`);
  }
  return note;
}

function parseAdjustableLine(value: unknown, sales: boolean): AdjustableLine | null {
  if (!isRecord(value)) {
    return null;
  }

  const idKey = sales ? "sales_invoice_line_id" : "purchase_bill_line_id";
  const priceKey = sales ? "unit_price" : "unit_cost";

  const source_line_id = isNumber(value[idKey]) ? value[idKey] : undefined;
  const line_number = isNumber(value.line_number) ? value.line_number : undefined;
  const quantity = isDecimalString(value.quantity) ? value.quantity : undefined;
  const unit_price = isDecimalString(value[priceKey]) ? value[priceKey] : undefined;
  const tax_rate = isDecimalString(value.tax_rate) ? value.tax_rate : undefined;
  const line_total = isDecimalString(value.line_total) ? value.line_total : undefined;
  const adjusted_quantity = isDecimalString(value.adjusted_quantity) ? value.adjusted_quantity : undefined;
  const remaining_quantity = isDecimalString(value.remaining_quantity) ? value.remaining_quantity : undefined;

  if (
    source_line_id === undefined ||
    line_number === undefined ||
    quantity === undefined ||
    unit_price === undefined ||
    tax_rate === undefined ||
    line_total === undefined ||
    adjusted_quantity === undefined ||
    remaining_quantity === undefined
  ) {
    return null;
  }

  return {
    source_line_id,
    line_number,
    description: asNullableString(value.description),
    quantity,
    unit_price,
    tax_rate,
    tax_id: asNullableNumber(value.tax_id),
    line_total,
    adjusted_quantity,
    remaining_quantity,
    is_adjustable: value.is_adjustable === true,
  };
}

export function parseAdjustableSource(raw: unknown): AdjustableSource | null {
  if (!isRecord(raw)) {
    return null;
  }

  const sales = isNumber(raw.sales_invoice_id);
  const purchase = isNumber(raw.purchase_bill_id);

  if (sales === purchase) {
    // Exactly one source id (or neither / both) is not a usable response.
    return null;
  }

  const source_id = sales ? (raw.sales_invoice_id as number) : (raw.purchase_bill_id as number);
  const document_number = sales
    ? typeof raw.invoice_number === "string"
      ? raw.invoice_number
      : null
    : typeof raw.bill_number === "string"
      ? raw.bill_number
      : null;

  const grand_total = isDecimalString(raw.grand_total) ? raw.grand_total : undefined;
  const net_adjustment = isDecimalString(raw.net_adjustment) ? raw.net_adjustment : undefined;
  const remaining_adjustable_amount = isDecimalString(raw.remaining_adjustable_amount)
    ? raw.remaining_adjustable_amount
    : undefined;

  if (grand_total === undefined || net_adjustment === undefined || remaining_adjustable_amount === undefined) {
    return null;
  }

  const lines: AdjustableLine[] = [];
  if (Array.isArray(raw.lines)) {
    for (const entry of raw.lines) {
      const line = parseAdjustableLine(entry, sales);
      if (line !== null) {
        lines.push(line);
      }
    }
  }

  return {
    source_document_type: sales ? "sales_invoice" : "purchase_bill",
    source_id,
    document_number,
    grand_total,
    net_adjustment,
    remaining_adjustable_amount,
    lines,
  };
}

export type CreditDebitNoteValidationErrors = Record<string, string>;

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const DECIMAL_PATTERN = /^\d+(?:\.\d{1,10})?$/;

function splitDecimal(value: string): { int: string; frac: string } {
  const [int, frac = ""] = value.split(".");
  return { int: int || "0", frac };
}

function multiplyDecimals(a: string, b: string): string {
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

function compareDecimals(a: string, b: string): number {
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

/** Mirrors the verified `Store/UpdateCreditDebitNoteRequest` rules. */
export function validateCreditDebitNoteWrite(
  payload: CreditDebitNoteWritePayload,
): CreditDebitNoteValidationErrors {
  const errors: CreditDebitNoteValidationErrors = {};

  if (payload.source_id === null || !Number.isInteger(payload.source_id) || payload.source_id <= 0) {
    errors.source_id = "Select the document this note adjusts.";
  }

  if (!DATE_PATTERN.test(payload.note_date)) {
    errors.note_date = "The note date must be in YYYY-MM-DD format.";
  }

  const reason = payload.reason.trim();
  if (reason === "") {
    errors.reason = "A credit or debit note must state why the adjustment is being made.";
  } else if (reason.length > 5000) {
    errors.reason = "The reason may not be longer than 5000 characters.";
  }

  if (payload.reference !== null && payload.reference.length > 100) {
    errors.reference = "The reference may not be longer than 100 characters.";
  }

  if (payload.notes !== null && payload.notes.length > 5000) {
    errors.notes = "The notes may not be longer than 5000 characters.";
  }

  if (payload.lines.length === 0) {
    errors.lines = "Add at least one note line.";
  }
  if (payload.lines.length > 500) {
    errors.lines = "A note may not have more than 500 lines.";
  }

  payload.lines.forEach((line, index) => {
    validateLine(line, index, errors);
  });

  return errors;
}

function validateLine(
  line: CreditDebitNoteLinePayload,
  index: number,
  errors: CreditDebitNoteValidationErrors,
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

  if (line.account_id === null || !Number.isInteger(line.account_id) || line.account_id <= 0) {
    errors[`${prefix}account_id`] = "Select an account.";
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
