/**
 * Runtime validation for tax responses and inputs.
 *
 * API JSON is untrusted input. The parsers below narrow a raw payload to the
 * exact fields the UI renders. A tax missing a required identifier, code, name,
 * a *known* tax type or calculation basis, or the derived side flags — is treated
 * as invalid and skipped, the same "filter the invalid row, never fabricate"
 * strategy the account and period validators use. Unknown enum values are never
 * coerced to a known one: a tax whose `tax_type` is not one of OUTPUT/INPUT/BOTH
 * cannot be rendered safely and is dropped rather than guessed at.
 *
 * Report parsers are deliberately lenient about row identity (a since-deleted tax
 * reports with null code/name/type) but strict about the monetary strings, which
 * are always rendered.
 */

import { normalizeList } from "@/lib/api/pagination";

import {
  TAX_CALCULATION_BASIS_VALUES,
  TAX_TYPE_VALUES,
  type Tax,
  type TaxAccountMapping,
  type TaxAccountRef,
  type TaxCalculation,
  type TaxCalculationBasisValue,
  type TaxCalculationComponent,
  type TaxCreatePayload,
  type TaxListState,
  type TaxRangeFilters,
  type TaxRate,
  type TaxRateListState,
  type TaxReport,
  type TaxReportBaseCurrency,
  type TaxReportRow,
  type TaxReportState,
  type TaxReportTotals,
  type TaxTypeValue,
  type TaxUpdatePayload,
} from "./types";

export class TaxesDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "TaxesDataError";
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function asString(value: unknown): string | undefined {
  return typeof value === "string" ? value : undefined;
}

function asNullableString(value: unknown): string | null {
  return typeof value === "string" ? value : null;
}

function asNumber(value: unknown): number | undefined {
  return typeof value === "number" && Number.isFinite(value) ? value : undefined;
}

function asNullableNumber(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

function asBoolean(value: unknown): boolean | undefined {
  return typeof value === "boolean" ? value : undefined;
}

function asTaxType(value: unknown): TaxTypeValue | undefined {
  return TAX_TYPE_VALUES.includes(value as TaxTypeValue)
    ? (value as TaxTypeValue)
    : undefined;
}

function asBasis(value: unknown): TaxCalculationBasisValue | undefined {
  return TAX_CALCULATION_BASIS_VALUES.includes(value as TaxCalculationBasisValue)
    ? (value as TaxCalculationBasisValue)
    : undefined;
}

function parseAccountRef(value: unknown): TaxAccountRef | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = asNumber(value.id);
  const code = asString(value.code);
  const name = asString(value.name);
  const account_type = asString(value.account_type);

  if (id === undefined || code === undefined || name === undefined || account_type === undefined) {
    return null;
  }

  return {
    id,
    code,
    name,
    account_type,
    is_active: asBoolean(value.is_active) ?? false,
  };
}

/**
 * Narrow a `TaxAccountMappingResource` body. Every field may be null (the tax is
 * simply not mapped), so a record is always accepted and only the account refs
 * degrade to null when malformed.
 */
export function parseTaxAccountMapping(value: unknown): TaxAccountMapping | null {
  if (!isRecord(value)) {
    return null;
  }

  return {
    tax_id: asNullableNumber(value.tax_id),
    output_account_id: asNullableNumber(value.output_account_id),
    input_account_id: asNullableNumber(value.input_account_id),
    output_account: parseAccountRef(value.output_account),
    input_account: parseAccountRef(value.input_account),
    created_by: asNullableNumber(value.created_by),
    updated_by: asNullableNumber(value.updated_by),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

/**
 * Narrow one tax row, or return null when it is not safe to render.
 *
 * Required: id, code, name, a known tax type and basis, and both derived side
 * flags. `current_rate`, `description` and the embedded mapping degrade to null.
 */
export function parseTax(value: unknown): Tax | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = asNumber(value.id);
  const code = asString(value.code);
  const name = asString(value.name);
  const tax_type = asTaxType(value.tax_type);
  const calculation_basis = asBasis(value.calculation_basis);
  const is_active = asBoolean(value.is_active);
  const applies_to_sales = asBoolean(value.applies_to_sales);
  const applies_to_purchase = asBoolean(value.applies_to_purchase);

  if (
    id === undefined ||
    code === undefined ||
    name === undefined ||
    tax_type === undefined ||
    calculation_basis === undefined ||
    is_active === undefined ||
    applies_to_sales === undefined ||
    applies_to_purchase === undefined
  ) {
    return null;
  }

  return {
    id,
    code,
    name,
    description: asNullableString(value.description),
    tax_type,
    calculation_basis,
    is_active,
    applies_to_sales,
    applies_to_purchase,
    current_rate: asNullableString(value.current_rate),
    account_mapping: parseTaxAccountMapping(value.account_mapping),
    created_by: asNullableNumber(value.created_by),
    updated_by: asNullableNumber(value.updated_by),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toErrorState(message: string): TaxListState {
  return { status: "error", items: [], message };
}

/**
 * Narrow a flat list payload into a validated `TaxListState`. Invalid rows are
 * skipped, never fabricated. The tax index is not paginated, so there is no
 * `hasMore`/`perPage` here: the whole list is returned in one response.
 */
export function parseTaxList(raw: unknown): TaxListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toErrorState("The taxes response was not a list.");
  }

  const list = normalizeList<unknown>(raw);
  const items: Tax[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const tax = parseTax(entry);

    if (tax === null) {
      skipped += 1;
      continue;
    }

    items.push(tax);
  }

  if (skipped > 0 && items.length === 0) {
    return toErrorState("The taxes response contained no valid tax rows.");
  }

  return { status: "ready", items };
}

/** Narrow a single write/read tax payload, or throw when malformed. */
export function parseTaxOrThrow(raw: unknown, label = "tax"): Tax {
  const tax = parseTax(raw);

  if (tax === null) {
    throw new TaxesDataError(`The ${label} response was invalid.`);
  }

  return tax;
}

/** Narrow one `TaxRateResource` row, or null when it is not safe to render. */
export function parseTaxRate(value: unknown): TaxRate | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = asNumber(value.id);
  const tax_id = asNumber(value.tax_id);
  const rate = asString(value.rate);
  const effective_from = asString(value.effective_from);
  const is_open_ended = asBoolean(value.is_open_ended);
  const is_active = asBoolean(value.is_active);

  if (
    id === undefined ||
    tax_id === undefined ||
    rate === undefined ||
    effective_from === undefined ||
    is_open_ended === undefined ||
    is_active === undefined
  ) {
    return null;
  }

  return {
    id,
    tax_id,
    rate,
    effective_from,
    effective_to: asNullableString(value.effective_to),
    is_open_ended,
    is_active,
    created_by: asNullableNumber(value.created_by),
    updated_by: asNullableNumber(value.updated_by),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

/** Narrow a flat rate-list payload into a validated `TaxRateListState`. */
export function parseTaxRateList(raw: unknown): TaxRateListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return { status: "error", items: [], message: "The tax rates response was not a list." };
  }

  const list = normalizeList<unknown>(raw);
  const items: TaxRate[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const rate = parseTaxRate(entry);

    if (rate === null) {
      skipped += 1;
      continue;
    }

    items.push(rate);
  }

  if (skipped > 0 && items.length === 0) {
    return {
      status: "error",
      items: [],
      message: "The tax rates response contained no valid rate rows.",
    };
  }

  return { status: "ready", items };
}

/** Narrow a single rate write response, or throw when malformed. */
export function parseTaxRateOrThrow(raw: unknown, label = "tax rate"): TaxRate {
  const rate = parseTaxRate(raw);

  if (rate === null) {
    throw new TaxesDataError(`The ${label} response was invalid.`);
  }

  return rate;
}

function parseCalculationComponent(value: unknown): TaxCalculationComponent | null {
  if (!isRecord(value)) {
    return null;
  }

  const tax_id = asNumber(value.tax_id);
  const tax_code = asString(value.tax_code);
  const tax_name = asString(value.tax_name);
  const tax_type = asString(value.tax_type);
  const tax_rate_id = asNumber(value.tax_rate_id);
  const rate = asString(value.rate);
  const taxable_amount = asString(value.taxable_amount);
  const tax_amount = asString(value.tax_amount);

  if (
    tax_id === undefined ||
    tax_code === undefined ||
    tax_name === undefined ||
    tax_type === undefined ||
    tax_rate_id === undefined ||
    rate === undefined ||
    taxable_amount === undefined ||
    tax_amount === undefined
  ) {
    return null;
  }

  return {
    tax_id,
    tax_code,
    tax_name,
    tax_type,
    tax_rate_id,
    rate,
    taxable_amount,
    tax_amount,
    output_account_id: asNullableNumber(value.output_account_id),
    input_account_id: asNullableNumber(value.input_account_id),
  };
}

/**
 * Narrow a `TaxCalculationResource` payload, or null when the decision itself
 * (basis and the four totals) is missing. A malformed component is dropped rather
 * than blanking the result, mirroring the closing-check strategy.
 */
export function parseTaxCalculation(raw: unknown): TaxCalculation | null {
  if (!isRecord(raw)) {
    return null;
  }

  const basis = asBasis(raw.basis);
  const taxable_amount = asString(raw.taxable_amount);
  const total_tax = asString(raw.total_tax);
  const gross_amount = asString(raw.gross_amount);

  if (
    basis === undefined ||
    taxable_amount === undefined ||
    total_tax === undefined ||
    gross_amount === undefined
  ) {
    return null;
  }

  const components: TaxCalculationComponent[] = [];

  if (Array.isArray(raw.tax_components)) {
    for (const entry of raw.tax_components) {
      const component = parseCalculationComponent(entry);
      if (component !== null) {
        components.push(component);
      }
    }
  }

  return { basis, taxable_amount, total_tax, gross_amount, tax_components: components };
}

function parseReportRow(value: unknown): TaxReportRow | null {
  if (!isRecord(value)) {
    return null;
  }

  const sales_taxable = asString(value.sales_taxable);
  const output_tax = asString(value.output_tax);
  const purchase_taxable = asString(value.purchase_taxable);
  const input_tax = asString(value.input_tax);
  const net_tax = asString(value.net_tax);
  const unattributed_tax = asString(value.unattributed_tax);

  if (
    sales_taxable === undefined ||
    output_tax === undefined ||
    purchase_taxable === undefined ||
    input_tax === undefined ||
    net_tax === undefined ||
    unattributed_tax === undefined
  ) {
    return null;
  }

  return {
    tax_id: asNullableNumber(value.tax_id),
    tax_code: asNullableString(value.tax_code),
    tax_name: asNullableString(value.tax_name),
    tax_type: asNullableString(value.tax_type),
    sales_taxable,
    output_tax,
    purchase_taxable,
    input_tax,
    net_tax,
    unattributed_tax,
  };
}

function parseReportTotals(value: unknown): TaxReportTotals | null {
  if (!isRecord(value)) {
    return null;
  }

  const sales_taxable = asString(value.sales_taxable);
  const output_tax = asString(value.output_tax);
  const purchase_taxable = asString(value.purchase_taxable);
  const input_tax = asString(value.input_tax);
  const net_tax = asString(value.net_tax);
  const unattributed_tax = asString(value.unattributed_tax);

  if (
    sales_taxable === undefined ||
    output_tax === undefined ||
    purchase_taxable === undefined ||
    input_tax === undefined ||
    net_tax === undefined ||
    unattributed_tax === undefined
  ) {
    return null;
  }

  return { sales_taxable, output_tax, purchase_taxable, input_tax, net_tax, unattributed_tax };
}

function parseBaseCurrency(value: unknown): TaxReportBaseCurrency | undefined {
  if (!isRecord(value)) {
    return undefined;
  }

  return {
    code: asNullableString(value.code),
    name: asNullableString(value.name),
    symbol: asNullableString(value.symbol),
    decimals: asNullableNumber(value.decimals),
  };
}

function parseReportPeriod(value: unknown): { from: string | null; to: string | null } | null {
  if (!isRecord(value)) {
    return null;
  }

  return {
    from: asNullableString(value.from),
    to: asNullableString(value.to),
  };
}

/**
 * Narrow a summary / by-tax report payload, or null when the period or totals are
 * missing. Rows are filtered individually so one malformed tax row cannot blank
 * the whole report.
 */
export function parseTaxReport(raw: unknown): TaxReport | null {
  if (!isRecord(raw)) {
    return null;
  }

  const period = parseReportPeriod(raw.period);
  const totals = parseReportTotals(raw.totals);

  if (period === null || totals === null) {
    return null;
  }

  const rows: TaxReportRow[] = [];

  if (Array.isArray(raw.rows)) {
    for (const entry of raw.rows) {
      const row = parseReportRow(entry);
      if (row !== null) {
        rows.push(row);
      }
    }
  }

  const report: TaxReport = { period, rows, totals };

  const baseCurrency = parseBaseCurrency(raw.base_currency);
  if (baseCurrency !== undefined) {
    report.base_currency = baseCurrency;
  }

  return report;
}

/** Build a report-list error state. */
export function taxReportErrorState(message: string): TaxReportState {
  return { status: "error", summary: null, byTax: null, message };
}

export type TaxValidationErrors = Record<string, string>;

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Client-side validation mirroring `StoreTaxRequest` / `UpdateTaxRequest`. The
 * create and edit forms both send the full field set, so one validator suffices;
 * the backend re-validates every write and its 422 is shown verbatim.
 */
export function validateTaxWrite(
  payload: TaxCreatePayload | TaxUpdatePayload,
): TaxValidationErrors {
  const errors: TaxValidationErrors = {};

  if ("code" in payload && payload.code !== undefined) {
    const code = payload.code.trim();

    if (!code) {
      errors.code = "The tax code is required.";
    } else if (code.length > 50) {
      errors.code = "The tax code may not be longer than 50 characters.";
    } else if (!/^[A-Za-z0-9._\- ]+$/.test(code)) {
      errors.code =
        "The tax code may contain letters, numbers, dots, dashes and spaces only.";
    }
  } else if (!("code" in payload)) {
    errors.code = "The tax code is required.";
  }

  if (payload.name === undefined || !payload.name.trim()) {
    errors.name = "The tax name is required.";
  } else if (payload.name.length > 255) {
    errors.name = "The tax name may not be longer than 255 characters.";
  }

  if (payload.description != null && payload.description.length > 1000) {
    errors.description = "The description may not be longer than 1000 characters.";
  }

  if (!TAX_TYPE_VALUES.includes(payload.tax_type as TaxTypeValue)) {
    errors.tax_type = "Select a tax type (OUTPUT, INPUT or BOTH).";
  }

  if (!TAX_CALCULATION_BASIS_VALUES.includes(payload.calculation_basis as TaxCalculationBasisValue)) {
    errors.calculation_basis = "Select a calculation basis (EXCLUSIVE or INCLUSIVE).";
  }

  return errors;
}

/**
 * Client-side validation mirroring `StoreTaxRateRequest` / `UpdateTaxRateRequest`.
 *
 * The rate is validated as a percentage the backend can store (0 ≤ rate < 100),
 * and the effective window is checked for ordering. Overlap with the existing rate
 * history is deliberately *not* pre-checked here: the backend owns that rule, and
 * its 422 message is shown verbatim rather than re-derived from possibly stale data.
 */
export function validateTaxRateWrite(payload: {
  rate: string;
  effective_from: string;
  effective_to: string | null;
}): TaxValidationErrors {
  const errors: TaxValidationErrors = {};

  const rate = payload.rate.trim();
  if (!rate) {
    errors.rate = "The tax rate is required.";
  } else if (!/^\d+(?:\.\d+)?$/.test(rate)) {
    errors.rate = "The tax rate must be a number of percent, for example 7.5 for 7.5%.";
  } else if (Number(rate) >= 100) {
    errors.rate = "The tax rate must be less than 100%.";
  }

  if (!DATE_PATTERN.test(payload.effective_from.trim())) {
    errors.effective_from = "Enter the effective-from date as YYYY-MM-DD.";
  }

  if (payload.effective_to !== null && payload.effective_to.trim() !== "") {
    if (!DATE_PATTERN.test(payload.effective_to.trim())) {
      errors.effective_to = "Enter the effective-to date as YYYY-MM-DD, or leave it empty.";
    } else if (
      !errors.effective_from &&
      payload.effective_to.trim() < payload.effective_from.trim()
    ) {
      errors.effective_to =
        "The effective-to date must not be before the effective-from date.";
    }
  }

  return errors;
}

/** Client-side validation for the read-only calculation tool. */
export function validateCalculation(input: {
  amount: string;
  date: string;
}): TaxValidationErrors {
  const errors: TaxValidationErrors = {};

  const amount = input.amount.trim();
  if (!amount) {
    errors.amount = "Enter an amount to calculate tax on.";
  } else if (!/^-?\d+(?:\.\d+)?$/.test(amount)) {
    errors.amount = "The amount must be a number.";
  }

  if (!DATE_PATTERN.test(input.date.trim())) {
    errors.date = "Enter the calculation date as YYYY-MM-DD.";
  }

  return errors;
}

/** True when both report date bounds are either empty or a valid Y-m-d date. */
export function validateReportRange(filters: TaxRangeFilters): TaxValidationErrors {
  const errors: TaxValidationErrors = {};

  if (filters.from && !DATE_PATTERN.test(filters.from)) {
    errors.from = "Enter the from date as YYYY-MM-DD.";
  }

  if (filters.to && !DATE_PATTERN.test(filters.to)) {
    errors.to = "Enter the to date as YYYY-MM-DD.";
  }

  if (!errors.from && !errors.to && filters.from && filters.to && filters.to < filters.from) {
    errors.to = "The to date must be on or after the from date.";
  }

  return errors;
}
