/**
 * Validators and parsers for financial reports.
 *
 * Every parser verifies the exact shape the backend service builds (see
 * `app/Services/Accounting/Reports/*`). A row that does not match is dropped
 * with a `null` result rather than coerced, so the view shows an error instead
 * of a figure that was never in the response. No field is invented here: the
 * account rows for profit & loss and the balance sheet carry
 * `{ account_id, code, name, amount }` and nothing else, because
 * `JournalReportService::signedRowsForType()` returns exactly that.
 */

import type { BalanceSheetResponse } from "./balance-sheet-types";
import type { GeneralLedgerResponse } from "./general-ledger-types";
import type { ProfitLossResponse } from "./profit-loss-types";
import type { TrialBalanceResponse } from "./trial-balance-types";

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const DECIMAL_RE = /^[+-]?\d+(\.\d+)?$/;

function isString(v: unknown): v is string {
  return typeof v === "string";
}

function isNumber(v: unknown): v is number {
  return typeof v === "number" && Number.isFinite(v);
}

function isBoolean(v: unknown): v is boolean {
  return typeof v === "boolean";
}

function isObject(v: unknown): v is Record<string, unknown> {
  return typeof v === "object" && v !== null && !Array.isArray(v);
}

function isIsoDate(v: unknown): v is string {
  return isString(v) && ISO_DATE.test(v);
}

function isDecimalLike(v: unknown): v is string {
  return isString(v) && DECIMAL_RE.test(v);
}

function isNullableString(v: unknown): v is string | null {
  return v === null || isString(v);
}

function isNullableIsoDate(v: unknown): v is string | null {
  return v === null || isIsoDate(v);
}

function isNullableDecimal(v: unknown): v is string | null {
  return v === null || isDecimalLike(v);
}

function isNullableNumber(v: unknown): v is number | null {
  return v === null || isNumber(v);
}

function validateBaseCurrency(v: unknown): boolean {
  if (!isObject(v)) return false;
  return (
    isNullableString(v.code) &&
    isNullableString(v.name) &&
    isNullableString(v.symbol) &&
    isNullableNumber(v.decimals)
  );
}

function validatePeriod(v: unknown): boolean {
  if (!isObject(v)) return false;
  return isNullableIsoDate(v.from) && isNullableIsoDate(v.to);
}

/**
 * `{ account_id, code, name, amount }` — the shared signed-account row produced
 * by `JournalReportService::signedRowsForType()`, used by P&L and the balance
 * sheet. Note there is deliberately no `account_type` field on this row.
 */
function validateAmountAccountRow(v: unknown): boolean {
  if (!isObject(v)) return false;
  return (
    isNumber(v.account_id) &&
    isString(v.code) &&
    isString(v.name) &&
    isDecimalLike(v.amount)
  );
}

function validateAmountSection(v: unknown): boolean {
  if (!isObject(v)) return false;
  if (!isDecimalLike(v.total)) return false;
  if (!Array.isArray(v.accounts)) return false;

  for (const row of v.accounts) {
    if (!validateAmountAccountRow(row)) return false;
  }

  return true;
}

export function parseTrialBalance(input: unknown): TrialBalanceResponse | null {
  if (!isObject(input)) return null;
  if (!validatePeriod(input.period)) return null;
  if (!validateBaseCurrency(input.base_currency)) return null;
  if (!isBoolean(input.include_zero_balances)) return null;
  if (!Array.isArray(input.rows)) return null;
  if (!isObject(input.totals)) return null;

  for (const row of input.rows) {
    if (!isObject(row)) return null;
    if (!isNumber(row.account_id)) return null;
    if (!isString(row.code)) return null;
    if (!isString(row.name)) return null;
    if (!isString(row.account_type)) return null;
    if (!isDecimalLike(row.debit_total)) return null;
    if (!isDecimalLike(row.credit_total)) return null;
    if (!isDecimalLike(row.net_balance)) return null;
  }

  const t = input.totals;
  if (!isDecimalLike(t.debit_total)) return null;
  if (!isDecimalLike(t.credit_total)) return null;
  if (!isDecimalLike(t.net_balance)) return null;
  if (!isBoolean(t.is_balanced)) return null;
  if (!isDecimalLike(t.difference)) return null;

  return input as TrialBalanceResponse;
}

export function parseProfitLoss(input: unknown): ProfitLossResponse | null {
  if (!isObject(input)) return null;
  if (!validatePeriod(input.period)) return null;
  if (!validateBaseCurrency(input.base_currency)) return null;
  if (!validateAmountSection(input.revenue)) return null;
  if (!validateAmountSection(input.expenses)) return null;
  if (!isDecimalLike(input.gross_profit)) return null;
  if (!isDecimalLike(input.net_profit)) return null;
  if (!isBoolean(input.is_profitable)) return null;

  return input as ProfitLossResponse;
}

export function parseGeneralLedger(input: unknown): GeneralLedgerResponse | null {
  if (!isObject(input)) return null;
  if (!isObject(input.account)) return null;
  if (!validatePeriod(input.period)) return null;
  if (!validateBaseCurrency(input.base_currency)) return null;
  if (!isDecimalLike(input.opening_balance)) return null;
  if (!Array.isArray(input.rows)) return null;
  if (!isDecimalLike(input.period_debits)) return null;
  if (!isDecimalLike(input.period_credits)) return null;
  if (!isDecimalLike(input.closing_balance)) return null;
  if (!isDecimalLike(input.closing_balance_signed)) return null;

  const acc = input.account;
  if (!isNumber(acc.id)) return null;
  if (!isString(acc.code)) return null;
  if (!isString(acc.name)) return null;
  if (!isString(acc.account_type)) return null;
  if (!isString(acc.normal_balance)) return null;
  if (!isBoolean(acc.is_active)) return null;

  for (const row of input.rows) {
    if (!isObject(row)) return null;
    if (!isNumber(row.journal_id)) return null;
    if (!isNumber(row.journal_line_id)) return null;
    if (!isNullableString(row.journal_number)) return null;
    if (!isIsoDate(row.journal_date)) return null;
    if (!isNullableString(row.reference)) return null;
    if (!isNullableString(row.description)) return null;
    if (!isString(row.source_type)) return null;
    if (!isNullableNumber(row.source_id)) return null;
    if (!isDecimalLike(row.debit)) return null;
    if (!isDecimalLike(row.credit)) return null;
    if (!isDecimalLike(row.running_balance)) return null;
    if (!isNullableNumber(row.currency_id)) return null;
    if (!isNullableString(row.exchange_rate)) return null;
    if (!isNullableDecimal(row.foreign_debit)) return null;
    if (!isNullableDecimal(row.foreign_credit)) return null;
  }

  return input as GeneralLedgerResponse;
}

/**
 * The cash/bank report reuses the general-ledger response verbatim
 * (`CashBankReportService` delegates to `GeneralLedgerReportService`). The
 * parser is a named alias so the cash/bank hook reads against its own contract
 * even though the shape is shared.
 */
export function parseCashBank(input: unknown): GeneralLedgerResponse | null {
  return parseGeneralLedger(input);
}

function validateBalanceSheetEquity(v: unknown): boolean {
  if (!isObject(v)) return false;
  if (!isDecimalLike(v.total)) return false;
  if (!Array.isArray(v.accounts)) return false;

  for (const row of v.accounts) {
    if (!validateAmountAccountRow(row)) return false;
  }

  return isDecimalLike(v.retained_earnings);
}

function validateBalanceSheetTotals(v: unknown): boolean {
  if (!isObject(v)) return false;
  return (
    isDecimalLike(v.assets) &&
    isDecimalLike(v.liabilities) &&
    isDecimalLike(v.equity) &&
    isDecimalLike(v.liabilities_and_equity) &&
    isBoolean(v.is_balanced) &&
    isDecimalLike(v.difference)
  );
}

export function parseBalanceSheet(input: unknown): BalanceSheetResponse | null {
  if (!isObject(input)) return null;
  if (!validatePeriod(input.period)) return null;
  if (!validateBaseCurrency(input.base_currency)) return null;
  if (!validateAmountSection(input.assets)) return null;
  if (!validateAmountSection(input.liabilities)) return null;
  if (!validateBalanceSheetEquity(input.equity)) return null;
  if (!validateBalanceSheetTotals(input.totals)) return null;
  if (!isDecimalLike(input.current_period_result)) return null;

  return input as BalanceSheetResponse;
}
