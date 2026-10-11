/**
 * Precision-safe decimal/money formatting.
 *
 * The backend sends money and quantities as decimal strings (up to 4 decimal
 * places, in the company base currency). These helpers format the string
 * directly and never round-trip through `Number`, so large amounts keep their
 * exact value. Grouping and the decimal separator come from `Intl`, while the
 * digits themselves are handled with string/BigInt arithmetic.
 */

const DECIMAL_PATTERN = /^[+-]?\d+(?:\.\d+)?$/;

/** True for a plain (optionally signed) decimal string such as `"1234.5600"`. */
export function isDecimalString(value: unknown): value is string {
  return typeof value === "string" && DECIMAL_PATTERN.test(value.trim());
}

export type FormatDecimalOptions = {
  /** BCP-47 locale used for group and decimal separators. Defaults to en-US. */
  locale?: string;
  /**
   * Fixed number of decimal places. Defaults to the value's own precision so
   * stored trailing zeros are preserved (for example `"10.5000"` renders as
   * `10.5000`).
   */
  decimals?: number;
  /** Returned for null, undefined, empty, or unparseable input. */
  fallback?: string;
};

function decimalSeparator(locale: string): string {
  try {
    const parts = new Intl.NumberFormat(locale).formatToParts(1.1);
    return parts.find((part) => part.type === "decimal")?.value ?? ".";
  } catch {
    return ".";
  }
}

function groupInteger(integer: string, locale: string): string {
  const digits = integer.replace(/^0+(?=\d)/, "") || "0";

  try {
    return new Intl.NumberFormat(locale, {
      useGrouping: true,
      maximumFractionDigits: 0,
    }).format(BigInt(digits));
  } catch {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  }
}

function roundFraction(
  integer: string,
  fraction: string,
  digits: number,
): { integer: string; fraction: string } {
  const safeInteger = integer || "0";

  if (digits <= 0) {
    let value = BigInt(safeInteger);
    if (fraction.length > 0 && Number(fraction[0]) >= 5) {
      value += BigInt(1);
    }
    return { integer: value.toString(), fraction: "" };
  }

  if (fraction.length === digits) {
    return { integer: safeInteger, fraction };
  }

  if (fraction.length < digits) {
    return { integer: safeInteger, fraction: fraction.padEnd(digits, "0") };
  }

  const kept = fraction.slice(0, digits);
  const nextDigit = Number(fraction[digits]);

  if (nextDigit < 5) {
    return { integer: safeInteger, fraction: kept };
  }

  let bumped = (BigInt(kept || "0") + BigInt(1)).toString();
  let integerValue = BigInt(safeInteger);

  if (bumped.length > digits) {
    integerValue += BigInt(1);
    bumped = "0".repeat(digits);
  } else {
    bumped = bumped.padStart(digits, "0");
  }

  return { integer: integerValue.toString(), fraction: bumped };
}

/**
 * Format a decimal string/number without losing precision.
 *
 * Invalid input returns `fallback` rather than a guess, so bad data is visible
 * instead of silently rendered as a wrong figure.
 */
export function formatDecimal(
  value: string | number | bigint | null | undefined,
  options: FormatDecimalOptions = {},
): string {
  const { locale = "en-US", decimals, fallback = "—" } = options;

  if (value === null || value === undefined) {
    return fallback;
  }

  const raw = typeof value === "string" ? value.trim() : String(value);
  const match = /^([+-]?)(\d+)(?:\.(\d+))?$/.exec(raw);

  if (!match) {
    return fallback;
  }

  const negative = match[1] === "-";
  const fractionRaw = match[3] ?? "";
  const targetDigits = Math.max(0, decimals ?? fractionRaw.length);

  const { integer, fraction } = roundFraction(
    match[2],
    fractionRaw,
    targetDigits,
  );

  const isZero = integer === "0" && fraction.replace(/0/g, "") === "";
  const sign = negative && !isZero ? "-" : "";
  const grouped = groupInteger(integer, locale);
  const body = fraction.length
    ? `${grouped}${decimalSeparator(locale)}${fraction}`
    : grouped;

  return `${sign}${body}`;
}

/** Recommended display precision for monetary amounts. */
export const MONEY_DISPLAY_DECIMALS = 2;

/** Money-typed alias of {@link formatDecimal}. */
export function formatMoney(
  value: string | number | bigint | null | undefined,
  options: FormatDecimalOptions = {},
): string {
  return formatDecimal(value, options);
}
