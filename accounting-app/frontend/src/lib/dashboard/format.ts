/**
 * Presentation helpers for dashboard money values.
 *
 * `sumDecimalStrings` is the only place the dashboard adds monetary amounts, and
 * it does so with BigInt fixed-point arithmetic so a total is never a
 * floating-point approximation. `formatMoneyValue` defers to the shared
 * precision-safe `formatMoney`.
 */

import { MONEY_DISPLAY_DECIMALS, formatMoney, isDecimalString } from "@/lib/format/money";

import type { CurrencyInfo } from "./types";

/** Minor-unit scale used for dashboard summation (backend money is max 4 dp). */
const SCALE_DIGITS = 4;
const SCALE_FACTOR = BigInt(10) ** BigInt(SCALE_DIGITS);

const DECIMAL_PATTERN = /^([+-]?)(\d+)(?:\.(\d+))?$/;

/**
 * Add decimal strings without floating point.
 *
 * Invalid entries are ignored rather than poisoning the total; callers validate
 * responses first, so a malformed value here means a defensive skip, not a
 * silent correction. The result is a canonical 4-decimal string.
 */
export function sumDecimalStrings(
  values: readonly (string | null | undefined)[],
): string {
  let total = BigInt(0);

  for (const value of values) {
    if (typeof value !== "string") {
      continue;
    }

    const match = DECIMAL_PATTERN.exec(value.trim());

    if (!match) {
      continue;
    }

    const sign = match[1] === "-" ? BigInt(-1) : BigInt(1);
    const fraction = (match[3] ?? "").padEnd(SCALE_DIGITS, "0").slice(0, SCALE_DIGITS);
    const minor = BigInt(match[2]) * SCALE_FACTOR + BigInt(fraction || "0");

    total += sign * minor;
  }

  const negative = total < BigInt(0);
  const absolute = negative ? -total : total;
  const digits = absolute.toString().padStart(SCALE_DIGITS + 1, "0");
  const integerPart = digits.slice(0, digits.length - SCALE_DIGITS);
  const fractionPart = digits.slice(digits.length - SCALE_DIGITS);

  return `${negative ? "-" : ""}${integerPart}.${fractionPart}`;
}

/** True when the currency carries enough information to render a label. */
export function hasCurrency(currency: CurrencyInfo | null | undefined): boolean {
  return Boolean(currency && (currency.code || currency.symbol));
}

/**
 * Format a decimal amount with the company currency.
 *
 * The number itself is grouped and rounded by the shared money utility; the
 * currency symbol or code is appended only when known. An unknown currency is
 * rendered as a plain amount so the value is still visible, never as a guessed
 * currency.
 */
export function formatMoneyValue(
  value: string | null | undefined,
  currency: CurrencyInfo | null | undefined,
): string {
  if (!isDecimalString(value)) {
    return "—";
  }

  const decimals =
    typeof currency?.decimals === "number" && currency.decimals >= 0
      ? currency.decimals
      : MONEY_DISPLAY_DECIMALS;

  const formatted = formatMoney(value, { decimals });
  const symbol = currency?.symbol?.trim();
  const code = currency?.code?.trim();

  if (symbol) {
    return `${symbol} ${formatted}`;
  }

  if (code) {
    return `${formatted} ${code}`;
  }

  return formatted;
}

/** Compact axis/legend formatting, e.g. `1.2K`; presentation only. */
export function formatCompactNumber(value: number): string {
  if (!Number.isFinite(value)) {
    return "0";
  }

  return new Intl.NumberFormat("en-US", {
    notation: "compact",
    maximumFractionDigits: 1,
  }).format(value);
}

/** Parse a decimal string to a Number for visual proportions only, never sums. */
export function toDisplayNumber(value: string | null | undefined): number {
  if (!isDecimalString(value)) {
    return 0;
  }

  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
}
