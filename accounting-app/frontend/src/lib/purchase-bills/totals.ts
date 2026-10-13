/**
 * Exact decimal arithmetic for the bill form's live preview.
 *
 * This mirrors `App\Services\Accounting\DocumentCalculator` line for line
 * (gross = quantity × unit_cost; net = gross − discount; tax = net ×
 * tax_rate / 100; total = net + tax) using string/BigInt arithmetic only. It is
 * used solely to give the user a readable estimate while editing — the backend
 * recomputes every figure on save and remains authoritative, so the preview is
 * always labelled as provisional.
 */

import type { PurchaseBillLinePayload } from "./types";

function parts(value: string): { int: string; frac: string } {
  const [int, frac = ""] = value.trim().split(".");
  return { int: int || "0", frac };
}

function toScaled(value: string, scale: number): bigint {
  const { int, frac } = parts(value);
  return BigInt(int + frac.padEnd(scale, "0").slice(0, scale) || "0");
}

function fromScaled(value: bigint, scale: number): string {
  const negative = value < BigInt(0);
  const digits = (negative ? -value : value).toString().padStart(scale + 1, "0");
  const int = scale === 0 ? digits : digits.slice(0, -scale);
  const frac = scale === 0 ? "" : digits.slice(-scale).replace(/0+$/, "");
  const sign = negative && (int !== "0" || frac !== "") ? "-" : "";
  return frac === "" ? `${sign}${int}` : `${sign}${int}.${frac}`;
}

export function multiplyDecimals(a: string, b: string): string {
  const da = parts(a);
  const db = parts(b);
  const product = BigInt(da.int + da.frac) * BigInt(db.int + db.frac);
  return fromScaled(product, da.frac.length + db.frac.length);
}

export function addDecimals(a: string, b: string): string {
  const scale = Math.max(parts(a).frac.length, parts(b).frac.length);
  return fromScaled(toScaled(a, scale) + toScaled(b, scale), scale);
}

export function subtractDecimals(a: string, b: string): string {
  const scale = Math.max(parts(a).frac.length, parts(b).frac.length);
  return fromScaled(toScaled(a, scale) - toScaled(b, scale), scale);
}

/** Divide by 100 exactly by shifting the decimal point two places left. */
export function divideByHundred(value: string): string {
  const negative = value.trim().startsWith("-");
  const { int, frac } = parts(value.replace("-", ""));
  const padded = int.padStart(3, "0");
  const shifted = `${padded.slice(0, -2)}.${padded.slice(-2)}${frac}`;
  return `${negative ? "-" : ""}${shifted}`.replace(/\.$/, "");
}

const ZERO = "0";

function safe(value: string): string {
  return value.trim() === "" ? ZERO : value;
}

export type LineTotals = {
  gross: string;
  net: string;
  taxAmount: string;
  lineTotal: string;
};

export function computeLineTotals(line: PurchaseBillLinePayload): LineTotals {
  const quantity = safe(line.quantity);
  const unitCost = safe(line.unit_cost);
  const discount = safe(line.discount);
  const taxRate = safe(line.tax_rate);

  const gross = multiplyDecimals(quantity, unitCost);
  const net = subtractDecimals(gross, discount);
  const taxAmount = divideByHundred(multiplyDecimals(net, taxRate));
  const lineTotal = addDecimals(net, taxAmount);

  return { gross, net, taxAmount, lineTotal };
}

export type BillTotals = {
  subtotal: string;
  discountTotal: string;
  taxTotal: string;
  grandTotal: string;
};

export function computeBillTotals(lines: PurchaseBillLinePayload[]): BillTotals {
  let subtotal = ZERO;
  let discountTotal = ZERO;
  let taxTotal = ZERO;
  let grandTotal = ZERO;

  for (const line of lines) {
    const totals = computeLineTotals(line);
    subtotal = addDecimals(subtotal, totals.gross);
    discountTotal = addDecimals(discountTotal, safe(line.discount));
    taxTotal = addDecimals(taxTotal, totals.taxAmount);
    grandTotal = addDecimals(grandTotal, totals.lineTotal);
  }

  return { subtotal, discountTotal, taxTotal, grandTotal };
}
