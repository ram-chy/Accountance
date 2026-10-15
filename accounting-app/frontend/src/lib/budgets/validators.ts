/**
 * Runtime validation for budget responses and inputs.
 *
 * API JSON is untrusted input. The parsers below narrow a raw payload to the
 * exact fields the UI renders, dropping an invalid row rather than fabricating
 * one — the same strategy the fixed-asset and dimension validators use.
 *
 * Amounts are decimal strings and stay strings end-to-end. The client validators
 * only sanity-check the shape of a value the user typed (present, non-negative);
 * they never convert a money string to a JavaScript number for arithmetic, and
 * no authoritative total is ever derived here.
 */

import { ACCOUNT_TYPE_VALUES, type AccountTypeValue } from "@/lib/accounts/types";
import { normalizeList } from "@/lib/api/pagination";
import {
  FINANCIAL_YEAR_STATUS_VALUES,
  type FinancialYearStatusValue,
} from "@/lib/financial-years/types";
import { isDecimalString } from "@/lib/format/money";
import { PERIOD_STATUS_VALUES, type PeriodStatusValue } from "@/lib/periods/types";

import {
  BUDGET_STATUS_VALUES,
  BUDGET_VARIANCE_STATUS_VALUES,
  type Budget,
  type BudgetAccountRef,
  type BudgetCreatePayload,
  type BudgetDetailState,
  type BudgetFinancialYearRef,
  type BudgetLine,
  type BudgetLineCreatePayload,
  type BudgetLineDimensionAssignment,
  type BudgetLineUpdatePayload,
  type BudgetListState,
  type BudgetPeriodRef,
  type BudgetRevisePayload,
  type BudgetStatusValue,
  type BudgetUpdatePayload,
  type BudgetVarianceLine,
  type BudgetVarianceReport,
  type BudgetVarianceState,
  type BudgetVarianceStatusValue,
} from "./types";

export class BudgetsDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "BudgetsDataError";
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

function asBudgetStatus(value: unknown): BudgetStatusValue | undefined {
  return BUDGET_STATUS_VALUES.includes(value as BudgetStatusValue)
    ? (value as BudgetStatusValue)
    : undefined;
}

function asVarianceStatus(value: unknown): BudgetVarianceStatusValue | undefined {
  return BUDGET_VARIANCE_STATUS_VALUES.includes(value as BudgetVarianceStatusValue)
    ? (value as BudgetVarianceStatusValue)
    : undefined;
}

function asAccountType(value: unknown): AccountTypeValue | undefined {
  return ACCOUNT_TYPE_VALUES.includes(value as AccountTypeValue)
    ? (value as AccountTypeValue)
    : undefined;
}

function asPeriodStatus(value: unknown): PeriodStatusValue | undefined {
  return PERIOD_STATUS_VALUES.includes(value as PeriodStatusValue)
    ? (value as PeriodStatusValue)
    : undefined;
}

function asYearStatus(value: unknown): FinancialYearStatusValue | undefined {
  return FINANCIAL_YEAR_STATUS_VALUES.includes(value as FinancialYearStatusValue)
    ? (value as FinancialYearStatusValue)
    : undefined;
}

function parseFinancialYearRef(value: unknown): BudgetFinancialYearRef | null {
  if (!isRecord(value)) return null;
  const id = asNumber(value.id);
  const name = asString(value.name);
  const start_date = asString(value.start_date);
  const end_date = asString(value.end_date);
  const status = asYearStatus(value.status);
  if (id === undefined || name === undefined || start_date === undefined || end_date === undefined || status === undefined) {
    return null;
  }
  return { id, name, start_date, end_date, status };
}

function parseAccountRef(value: unknown): BudgetAccountRef | null {
  if (!isRecord(value)) return null;
  const id = asNumber(value.id);
  const code = asString(value.code);
  const name = asString(value.name);
  const account_type = asAccountType(value.account_type);
  const is_active = asBoolean(value.is_active);
  if (id === undefined || code === undefined || name === undefined || account_type === undefined || is_active === undefined) {
    return null;
  }
  return { id, code, name, account_type, is_active };
}

function parsePeriodRef(value: unknown): BudgetPeriodRef | null {
  if (!isRecord(value)) return null;
  const id = asNumber(value.id);
  const name = asString(value.name);
  const start_date = asString(value.start_date);
  const end_date = asString(value.end_date);
  const status = asPeriodStatus(value.status);
  if (id === undefined || name === undefined || start_date === undefined || end_date === undefined || status === undefined) {
    return null;
  }
  return {
    id,
    name,
    start_date,
    end_date,
    status,
    financial_year_id: asNullableNumber(value.financial_year_id),
  };
}

export function parseBudgetLineDimensionAssignment(
  value: unknown,
): BudgetLineDimensionAssignment | null {
  if (!isRecord(value)) return null;
  const dimension_id = asNumber(value.dimension_id);
  const value_id = asNumber(value.value_id);
  if (dimension_id === undefined || value_id === undefined) return null;
  return { dimension_id, value_id };
}

export function parseBudgetLine(value: unknown): BudgetLine | null {
  if (!isRecord(value)) return null;

  const id = asNumber(value.id);
  const budget_id = asNumber(value.budget_id);
  const account_id = asNumber(value.account_id);
  const accounting_period_id = asNumber(value.accounting_period_id);
  const amount = asString(value.amount);

  if (
    id === undefined ||
    budget_id === undefined ||
    account_id === undefined ||
    accounting_period_id === undefined ||
    amount === undefined ||
    !isDecimalString(amount)
  ) {
    return null;
  }

  let dimensions: BudgetLineDimensionAssignment[] | undefined;
  if (Array.isArray(value.dimensions)) {
    dimensions = [];
    for (const entry of value.dimensions) {
      const parsed = parseBudgetLineDimensionAssignment(entry);
      if (parsed !== null) dimensions.push(parsed);
    }
  }

  return {
    id,
    budget_id,
    account_id,
    accounting_period_id,
    amount,
    description: asNullableString(value.description),
    account: parseAccountRef(value.account),
    accounting_period: parsePeriodRef(value.accounting_period),
    dimensions,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

export function parseBudget(value: unknown): Budget | null {
  if (!isRecord(value)) return null;

  const id = asNumber(value.id);
  const company_id = asNumber(value.company_id);
  const financial_year_id = asNumber(value.financial_year_id);
  const code = asString(value.code);
  const name = asString(value.name);
  const version_number = asNumber(value.version_number);
  const status = asBudgetStatus(value.status);

  if (
    id === undefined ||
    company_id === undefined ||
    financial_year_id === undefined ||
    code === undefined ||
    name === undefined ||
    version_number === undefined ||
    status === undefined
  ) {
    return null;
  }

  let lines: BudgetLine[] | undefined;
  if (Array.isArray(value.lines)) {
    lines = [];
    for (const entry of value.lines) {
      const parsed = parseBudgetLine(entry);
      if (parsed !== null) lines.push(parsed);
    }
  }

  return {
    id,
    company_id,
    financial_year_id,
    code,
    name,
    version_number,
    parent_budget_id: asNullableNumber(value.parent_budget_id),
    status,
    notes: asNullableString(value.notes),
    created_by: asNullableNumber(value.created_by),
    updated_by: asNullableNumber(value.updated_by),
    approved_by: asNullableNumber(value.approved_by),
    approved_at: asNullableString(value.approved_at),
    financial_year: parseFinancialYearRef(value.financial_year),
    lines,
    lines_count: asNullableNumber(value.lines_count) ?? undefined,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

export function parseBudgetOrThrow(raw: unknown, label = "budget"): Budget {
  const budget = parseBudget(raw);
  if (budget === null) {
    throw new BudgetsDataError(`The ${label} response was invalid.`);
  }
  return budget;
}

export function parseBudgetLineOrThrow(raw: unknown, label = "budget line"): BudgetLine {
  const line = parseBudgetLine(raw);
  if (line === null) {
    throw new BudgetsDataError(`The ${label} response was invalid.`);
  }
  return line;
}

export function parseBudgetList(raw: unknown, perPage: number): BudgetListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return { status: "error", items: [], perPage, hasMore: false, message: "The budget response was not a list." };
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: Budget[] = [];
  for (const entry of list.items) {
    const budget = parseBudget(entry);
    if (budget !== null) items.push(budget);
  }

  if (items.length === 0 && list.items.length > 0) {
    return {
      status: "error",
      items: [],
      perPage,
      hasMore: false,
      message: "The budget response contained no valid rows.",
    };
  }

  return { status: "ready", items, perPage, hasMore: list.hasMore };
}

export function parseBudgetDetail(raw: unknown): BudgetDetailState {
  const budget = parseBudget(raw);
  if (budget === null) {
    return { status: "error", budget: null, message: "The budget response was invalid." };
  }
  return { status: "ready", budget };
}

function parseVarianceLine(value: unknown): BudgetVarianceLine | null {
  if (!isRecord(value)) return null;

  const account_id = asNumber(value.account_id);
  const account_code = asString(value.account_code);
  const account_name = asString(value.account_name);
  const account_type = asAccountType(value.account_type);
  const accounting_period_id = asNumber(value.accounting_period_id);
  const period_name = asString(value.period_name);
  const period_start = asString(value.period_start);
  const period_end = asString(value.period_end);
  const budget = asString(value.budget);
  const actual = asString(value.actual);
  const variance = asString(value.variance);
  const status = asVarianceStatus(value.status);

  if (
    account_id === undefined ||
    account_code === undefined ||
    account_name === undefined ||
    account_type === undefined ||
    accounting_period_id === undefined ||
    period_name === undefined ||
    period_start === undefined ||
    period_end === undefined ||
    budget === undefined ||
    actual === undefined ||
    variance === undefined ||
    status === undefined
  ) {
    return null;
  }

  const percentageRaw = value.variance_percentage;
  const variance_percentage =
    percentageRaw === null ? null : typeof percentageRaw === "string" ? percentageRaw : null;

  return {
    account_id,
    account_code,
    account_name,
    account_type,
    accounting_period_id,
    period_name,
    period_start,
    period_end,
    budget,
    actual,
    variance,
    variance_percentage,
    status,
  };
}

function parseSummarySection(value: unknown): { budget: string; actual: string; variance: string } | null {
  if (!isRecord(value)) return null;
  const budget = asString(value.budget);
  const actual = asString(value.actual);
  const variance = asString(value.variance);
  if (budget === undefined || actual === undefined || variance === undefined) return null;
  return { budget, actual, variance };
}

export function parseBudgetVariance(raw: unknown): BudgetVarianceReport | null {
  if (!isRecord(raw)) return null;

  const budgetRaw = raw.budget;
  if (!isRecord(budgetRaw)) return null;

  const budgetId = asNumber(budgetRaw.id);
  const code = asString(budgetRaw.code);
  const name = asString(budgetRaw.name);
  const version_number = asNumber(budgetRaw.version_number);
  const status = asBudgetStatus(budgetRaw.status);
  if (
    budgetId === undefined ||
    code === undefined ||
    name === undefined ||
    version_number === undefined ||
    status === undefined
  ) {
    return null;
  }

  const yearRaw = isRecord(budgetRaw.financial_year) ? budgetRaw.financial_year : null;
  const financial_year = {
    id: yearRaw ? asNullableNumber(yearRaw.id) : null,
    name: yearRaw ? asNullableString(yearRaw.name) : null,
    start_date: yearRaw ? asNullableString(yearRaw.start_date) : null,
    end_date: yearRaw ? asNullableString(yearRaw.end_date) : null,
  };

  const periodRaw = isRecord(raw.period) ? raw.period : null;
  const period = {
    from: periodRaw ? asNullableString(periodRaw.from) : null,
    to: periodRaw ? asNullableString(periodRaw.to) : null,
  };

  const currencyRaw = isRecord(raw.base_currency) ? raw.base_currency : null;
  const base_currency = {
    code: currencyRaw ? asNullableString(currencyRaw.code) : null,
    name: currencyRaw ? asNullableString(currencyRaw.name) : null,
    symbol: currencyRaw ? asNullableString(currencyRaw.symbol) : null,
    decimals: currencyRaw ? asNullableNumber(currencyRaw.decimals) : null,
  };

  const summaryRaw = raw.summary;
  if (!isRecord(summaryRaw)) return null;

  const revenue = parseSummarySection(summaryRaw.revenue);
  const expenses = parseSummarySection(summaryRaw.expenses);
  const netRaw = summaryRaw.net;
  if (!isRecord(netRaw)) return null;
  const netSection = parseSummarySection(netRaw);
  const netStatus = asVarianceStatus(netRaw.status);
  const countsRaw = isRecord(summaryRaw.counts) ? summaryRaw.counts : null;
  if (revenue === null || expenses === null || netSection === null || netStatus === undefined) {
    return null;
  }

  const counts = {
    favourable: countsRaw ? asNumber(countsRaw.favourable) ?? 0 : 0,
    unfavourable: countsRaw ? asNumber(countsRaw.unfavourable) ?? 0 : 0,
    on_target: countsRaw ? asNumber(countsRaw.on_target) ?? 0 : 0,
  };

  const lines: BudgetVarianceLine[] = [];
  if (Array.isArray(raw.lines)) {
    for (const entry of raw.lines) {
      const parsed = parseVarianceLine(entry);
      if (parsed !== null) lines.push(parsed);
    }
  }

  const filterRaw = isRecord(raw.dimension_filter) ? raw.dimension_filter : null;
  const dimension_filter = filterRaw
    ? {
        dimension_id: asNullableNumber(filterRaw.dimension_id),
        dimension_value_id: asNullableNumber(filterRaw.dimension_value_id),
      }
    : undefined;

  return {
    budget: {
      id: budgetId,
      code,
      name,
      version_number,
      status,
      financial_year,
    },
    period,
    base_currency,
    summary: {
      revenue,
      expenses,
      net: { ...netSection, status: netStatus },
      counts,
    },
    lines,
    dimension_filter,
  };
}

export function parseBudgetVarianceState(raw: unknown): BudgetVarianceState {
  const report = parseBudgetVariance(raw);
  if (report === null) {
    return { status: "error", data: null, message: "The budget variance response was invalid." };
  }
  return { status: "ready", data: report };
}

// ---------------------------------------------------------------------------
// Input validation (shape/sanity only; the backend is authoritative)
// ---------------------------------------------------------------------------

export type BudgetValidationErrors = Record<string, string>;

/** `^\+?\d+(\.\d+)?$` — a non-negative decimal that never touches `Number`. */
const NON_NEGATIVE_DECIMAL = /^\+?\d+(\.\d+)?$/;

function isNonNegativeDecimal(value: string): boolean {
  return NON_NEGATIVE_DECIMAL.test(value.trim());
}

/** A non-negative decimal with at least one non-zero digit. */
function isPositiveDecimal(value: string): boolean {
  return isNonNegativeDecimal(value) && /[1-9]/.test(value);
}

export function validateBudgetCreate(payload: BudgetCreatePayload): BudgetValidationErrors {
  const errors: BudgetValidationErrors = {};
  if (payload.financial_year_id <= 0) errors.financial_year_id = "Financial year is required.";
  if (!payload.code?.trim()) errors.code = "Code is required.";
  if (!payload.name?.trim()) errors.name = "Name is required.";
  return errors;
}

export function validateBudgetUpdate(payload: BudgetUpdatePayload): BudgetValidationErrors {
  const errors: BudgetValidationErrors = {};
  if (payload.code !== undefined && !payload.code.trim()) errors.code = "Code is required.";
  if (payload.name !== undefined && !payload.name.trim()) errors.name = "Name is required.";
  return errors;
}

export function validateBudgetRevise(payload: BudgetRevisePayload): BudgetValidationErrors {
  const errors: BudgetValidationErrors = {};
  if (payload.name !== undefined && !payload.name.trim()) errors.name = "Name is required.";
  return errors;
}

export function validateBudgetLineCreate(payload: BudgetLineCreatePayload): BudgetValidationErrors {
  const errors: BudgetValidationErrors = {};
  if (payload.account_id <= 0) errors.account_id = "Account is required.";
  if (payload.accounting_period_id <= 0) errors.accounting_period_id = "Period is required.";
  if (!payload.amount || !isNonNegativeDecimal(payload.amount)) {
    errors.amount = "Enter a non-negative amount.";
  }
  if (payload.description !== null && payload.description.length > 500) {
    errors.description = "Description must be 500 characters or fewer.";
  }
  return errors;
}

export function validateBudgetLineUpdate(payload: BudgetLineUpdatePayload): BudgetValidationErrors {
  const errors: BudgetValidationErrors = {};
  if (payload.account_id !== undefined && payload.account_id <= 0) errors.account_id = "Invalid account.";
  if (payload.accounting_period_id !== undefined && payload.accounting_period_id <= 0) {
    errors.accounting_period_id = "Invalid period.";
  }
  if (payload.amount !== undefined && (!payload.amount || !isNonNegativeDecimal(payload.amount))) {
    errors.amount = "Enter a non-negative amount.";
  }
  if (payload.description !== undefined && payload.description !== null && payload.description.length > 500) {
    errors.description = "Description must be 500 characters or fewer.";
  }
  return errors;
}

/** Exposed for a form that must show a "greater than zero" hint before submit. */
export function isPositiveBudgetAmount(value: string): boolean {
  return isPositiveDecimal(value);
}
