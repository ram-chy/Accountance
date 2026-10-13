/**
 * Exact decimal arithmetic for the note form's live preview.
 *
 * Same shape as `DocumentCalculator` (gross = quantity × unit_price; net =
 * gross − discount; tax = net × tax_rate / 100; total = net + tax), reusing the
 * string/BigInt helpers from the sales-invoice module. The preview is advisory
 * only: the backend recomputes every figure from the lines on save and at
 * posting, and owns the adjustment limit.
 */

import {
  addDecimals,
  divideByHundred,
  multiplyDecimals,
  subtractDecimals,
} from "@/lib/sales-invoices/totals";

import type { CreditDebitNoteLinePayload } from "./types";

const ZERO = "0";

function safe(value: string): string {
  return value.trim() === "" ? ZERO : value.trim();
}

export function computeNoteLineTotals(line: CreditDebitNoteLinePayload) {
  const gross = multiplyDecimals(safe(line.quantity), safe(line.unit_price));
  const net = subtractDecimals(gross, safe(line.discount));
  const taxAmount = divideByHundred(multiplyDecimals(net, safe(line.tax_rate)));
  const lineTotal = addDecimals(net, taxAmount);

  return { gross, net, taxAmount, lineTotal };
}

export type NoteTotals = {
  subtotal: string;
  discountTotal: string;
  taxTotal: string;
  grandTotal: string;
};

export function computeNoteTotals(lines: CreditDebitNoteLinePayload[]): NoteTotals {
  let subtotal = ZERO;
  let discountTotal = ZERO;
  let taxTotal = ZERO;
  let grandTotal = ZERO;

  for (const line of lines) {
    const totals = computeNoteLineTotals(line);
    subtotal = addDecimals(subtotal, totals.gross);
    discountTotal = addDecimals(discountTotal, safe(line.discount));
    taxTotal = addDecimals(taxTotal, totals.taxAmount);
    grandTotal = addDecimals(grandTotal, totals.lineTotal);
  }

  return { subtotal, discountTotal, taxTotal, grandTotal };
}
