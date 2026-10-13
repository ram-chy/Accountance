"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import {
  noteSourceField,
  noteSourceLineField,
  type CreditDebitNoteListQuery,
  type CreditDebitNoteWritePayload,
} from "./types";

/**
 * Credit / debit note backend calls through the same-origin proxy.
 *
 * One resource for all four note types. The UI holds a uniform `source_id` /
 * `source_line_id`; this module maps them onto the concrete field the note's
 * type requires (`sales_invoice_id` / `purchase_bill_id` and their line
 * counterparts) so no component has to branch on the type.
 *
 * On create the type and source are sent; on update they are omitted, because
 * `UpdateCreditDebitNoteRequest` has no rule for them and the source and type
 * are immutable.
 */

type WireLine = Record<string, unknown>;

function toWireLines(payload: CreditDebitNoteWritePayload): WireLine[] {
  const lineField = noteSourceLineField(payload.note_type);

  return payload.lines.map((line) => {
    const wire: WireLine = {
      description: line.description,
      quantity: line.quantity,
      unit_price: line.unit_price,
      discount: line.discount,
      tax_rate: line.tax_rate,
      account_id: line.account_id,
    };

    if (line.source_line_id !== null) {
      wire[lineField] = line.source_line_id;
    }

    return wire;
  });
}

function toCreateBody(payload: CreditDebitNoteWritePayload): Record<string, unknown> {
  return {
    note_type: payload.note_type,
    [noteSourceField(payload.note_type)]: payload.source_id,
    note_date: payload.note_date,
    reason: payload.reason,
    reference: payload.reference,
    notes: payload.notes,
    tax_account_id: payload.tax_account_id,
    lines: toWireLines(payload),
  };
}

function toUpdateBody(payload: CreditDebitNoteWritePayload): Record<string, unknown> {
  return {
    note_date: payload.note_date,
    reason: payload.reason,
    reference: payload.reference,
    notes: payload.notes,
    tax_account_id: payload.tax_account_id,
    lines: toWireLines(payload),
  };
}

export function fetchCreditDebitNotes(
  query: CreditDebitNoteListQuery,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    note_type: query.note_type || undefined,
    status: query.status || undefined,
    sales_invoice_id: query.sales_invoice_id,
    purchase_bill_id: query.purchase_bill_id,
    customer_id: query.customer_id,
    supplier_id: query.supplier_id,
    search: query.search || undefined,
    from: query.from || undefined,
    to: query.to || undefined,
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("credit-debit-notes", { query: params, signal });
}

export function fetchCreditDebitNote(noteId: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`credit-debit-notes/${noteId}`, { signal });
}

export function createCreditDebitNote(payload: CreditDebitNoteWritePayload): Promise<unknown> {
  return backendPost("credit-debit-notes", toCreateBody(payload));
}

export function updateCreditDebitNote(
  noteId: number,
  payload: CreditDebitNoteWritePayload,
): Promise<unknown> {
  return backendPut(`credit-debit-notes/${noteId}`, toUpdateBody(payload));
}

export function deleteCreditDebitNote(noteId: number): Promise<unknown> {
  return backendDelete(`credit-debit-notes/${noteId}`);
}

/** Post a draft note. No body is accepted; a posted note 409s. */
export function postCreditDebitNote(noteId: number): Promise<unknown> {
  return backendPost(`credit-debit-notes/${noteId}/post`);
}

/**
 * What is still adjustable on a source document.
 *
 * Served from the invoice/bill rather than the notes prefix because the note id
 * does not exist yet when the question is asked.
 */
export function fetchAdjustableLines(
  noteType: CreditDebitNoteWritePayload["note_type"],
  sourceId: number,
  signal?: AbortSignal,
): Promise<unknown> {
  const family = isSales(noteType) ? "sales-invoices" : "purchase-bills";
  return backendGet(`${family}/${sourceId}/adjustable-lines`, { signal });
}

function isSales(noteType: CreditDebitNoteWritePayload["note_type"]): boolean {
  return noteType === "SALES_CREDIT_NOTE" || noteType === "SALES_DEBIT_NOTE";
}
