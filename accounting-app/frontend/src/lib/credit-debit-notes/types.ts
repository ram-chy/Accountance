/**
 * Credit / debit note types.
 *
 * Mirrors `App\Http\Resources\CreditDebitNoteResource` and
 * `CreditDebitNoteLineResource`. The backend exposes ONE resource
 * (`credit-debit-notes`) for all four note types — a sales note adjusts an
 * invoice, a purchase note adjusts a bill — and the note's `note_type` decides
 * the counterparty, the account role on each line and which field carries the
 * source document.
 *
 * Notes reuse the two-state `TransactionStatus` (DRAFT | POSTED). Money is a
 * decimal string.
 *
 * NO CURRENCY FIELDS
 *
 * The resource deliberately does not expose `currency_id`, `exchange_rate` or
 * base-currency totals, so neither do these types: the UI must not invent a
 * currency display the server never sent.
 */

export const NOTE_TYPE_VALUES = [
  "SALES_CREDIT_NOTE",
  "SALES_DEBIT_NOTE",
  "PURCHASE_CREDIT_NOTE",
  "PURCHASE_DEBIT_NOTE",
] as const;

export type NoteType = (typeof NOTE_TYPE_VALUES)[number];

/** The two note types Phase 16 exposes (sales credit / purchase debit). */
export const SALES_CREDIT_NOTE: NoteType = "SALES_CREDIT_NOTE";
export const PURCHASE_DEBIT_NOTE: NoteType = "PURCHASE_DEBIT_NOTE";

export const NOTE_STATUS_VALUES = ["DRAFT", "POSTED"] as const;

export type NoteStatus = (typeof NOTE_STATUS_VALUES)[number];

/** Which kind of document a note adjusts. */
export type NoteDocumentType = "sales_invoice" | "purchase_bill";

/** A sales note adjusts a sales invoice; a purchase note adjusts a bill. */
export function isSalesNote(noteType: NoteType): boolean {
  return noteType === "SALES_CREDIT_NOTE" || noteType === "SALES_DEBIT_NOTE";
}

export function isCreditNote(noteType: NoteType): boolean {
  return noteType === "SALES_CREDIT_NOTE" || noteType === "PURCHASE_CREDIT_NOTE";
}

export function noteDocumentType(noteType: NoteType): NoteDocumentType {
  return isSalesNote(noteType) ? "sales_invoice" : "purchase_bill";
}

/** The request/response field carrying the source document id for a type. */
export function noteSourceField(noteType: NoteType): "sales_invoice_id" | "purchase_bill_id" {
  return isSalesNote(noteType) ? "sales_invoice_id" : "purchase_bill_id";
}

/** The request field carrying a source line id for a type. */
export function noteSourceLineField(
  noteType: NoteType,
): "sales_invoice_line_id" | "purchase_bill_line_id" {
  return isSalesNote(noteType) ? "sales_invoice_line_id" : "purchase_bill_line_id";
}

export type NoteCounterpartyRef = {
  id: number;
  code: string;
  name: string;
};

export type CreditDebitNoteLine = {
  id: number;
  line_number: number;
  description: string | null;
  quantity: string;
  unit_price: string;
  discount: string;
  tax_rate: string;
  line_total: string;
  tax_amount: string;
  tax_id: number | null;
  account_id: number;
  source_document_type: NoteDocumentType | null;
  /** The adjusted source line, or null for a document-level adjustment. */
  source_line_id: number | null;
  created_at: string | null;
  updated_at: string | null;
};

export type CreditDebitNote = {
  id: number;
  note_number: string;
  note_type: NoteType;
  is_credit: boolean;
  label: string;
  note_date: string;
  status: NoteStatus;
  subtotal: string;
  discount_total: string;
  tax_total: string;
  grand_total: string;
  reason: string;
  reference: string | null;
  notes: string | null;
  source_document_type: NoteDocumentType;
  source_document_id: number;
  source_document_number: string | null;
  sales_invoice_id: number | null;
  purchase_bill_id: number | null;
  customer_id: number | null;
  supplier_id: number | null;
  customer?: NoteCounterpartyRef | null;
  supplier?: NoteCounterpartyRef | null;
  tax_account_id: number | null;
  journal_id: number | null;
  posted_at: string | null;
  lines: CreditDebitNoteLine[];
  created_by: number | null;
  posted_by: number | null;
  created_at: string | null;
  updated_at: string | null;
};

/**
 * A note line as the UI holds it.
 *
 * `source_line_id` is the uniform name for `sales_invoice_line_id` /
 * `purchase_bill_line_id`; `api.ts` maps it onto the field the note's type
 * requires. `account_id` plays the revenue-account role on a sales note and the
 * expense-account role on a purchase one.
 */
export type CreditDebitNoteLinePayload = {
  description: string | null;
  quantity: string;
  unit_price: string;
  discount: string;
  tax_rate: string;
  account_id: number | null;
  source_line_id: number | null;
};

/**
 * A note as the UI edits it.
 *
 * `note_type` and `source_id` are sent on create and omitted on update (the
 * source and type are immutable, per `UpdateCreditDebitNoteRequest`).
 */
export type CreditDebitNoteWritePayload = {
  note_type: NoteType;
  source_id: number | null;
  note_date: string;
  reason: string;
  reference: string | null;
  notes: string | null;
  tax_account_id: number | null;
  lines: CreditDebitNoteLinePayload[];
};

export type CreditDebitNoteListQuery = {
  note_type?: NoteType;
  status?: NoteStatus;
  sales_invoice_id?: number;
  purchase_bill_id?: number;
  customer_id?: number;
  supplier_id?: number;
  search?: string;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
};

export type CreditDebitNoteListState =
  | { status: "loading"; items: []; perPage: number; hasMore: boolean }
  | { status: "ready"; items: CreditDebitNote[]; perPage: number; hasMore: boolean }
  | { status: "error"; items: []; perPage: number; hasMore: boolean; message: string; requestId?: string }
  | { status: "unavailable"; items: []; perPage: number; hasMore: boolean; message: string };

export type CreditDebitNoteDetailState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: CreditDebitNote }
  | { status: "notfound"; data: null }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };

/**
 * One adjustable source line from `GET .../adjustable-lines`.
 *
 * The price field is `unit_price` on an invoice and `unit_cost` on a bill; the
 * parser normalises both to `unit_price`. `remaining_quantity` is what a user
 * may still credit; `is_adjustable` is false once nothing remains.
 */
export type AdjustableLine = {
  source_line_id: number;
  line_number: number;
  description: string | null;
  quantity: string;
  unit_price: string;
  tax_rate: string;
  tax_id: number | null;
  line_total: string;
  adjusted_quantity: string;
  remaining_quantity: string;
  is_adjustable: boolean;
};

/** The whole-document adjustment figures plus its adjustable lines. */
export type AdjustableSource = {
  source_document_type: NoteDocumentType;
  source_id: number;
  document_number: string | null;
  grand_total: string;
  net_adjustment: string;
  remaining_adjustable_amount: string;
  lines: AdjustableLine[];
};

export type AdjustableSourceState =
  | { status: "idle"; data: null }
  | { status: "loading"; data: null }
  | { status: "ready"; data: AdjustableSource }
  | { status: "error"; data: null; message: string };

/** A posted source document, for the note form's source picker. */
export type NoteSourceDocument = {
  id: number;
  number: string;
  date: string;
  counterparty: string | null;
  grand_total: string;
};

export type NoteSourceDocumentsState = {
  status: "loading" | "ready" | "error";
  items: NoteSourceDocument[];
  message?: string;
};
