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
import type { CustomerStatementResponse } from "./customer-statement-types";
import type { ReceivablesResponse } from "./receivables-types";
import type { ReceivablesAgingResponse } from "./receivables-aging-types";
import type { SupplierStatementResponse } from "./supplier-statement-types";
import type { PayablesResponse } from "./payables-types";
import type { PayablesAgingResponse } from "./payables-aging-types";

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

/**
 * `{ id, code, name }` — the counterparty summary shared by the customer
 * statement and the receivables/aging reports. `id` can be null on a receivables
 * row whose invoice lost its customer, but the object itself is always present.
 */
function validateCounterpartySummary(v: unknown): boolean {
  if (!isObject(v)) return false;
  return isNullableNumber(v.id) && isNullableString(v.code) && isNullableString(v.name);
}

export function parseCustomerStatement(input: unknown): CustomerStatementResponse | null {
  if (!isObject(input)) return null;
  if (!validateCounterpartySummary(input.customer)) return null;
  if (!validatePeriod(input.period)) return null;
  if (!validateBaseCurrency(input.base_currency)) return null;
  if (!isDecimalLike(input.opening_balance)) return null;
  if (!Array.isArray(input.rows)) return null;
  if (!isObject(input.totals)) return null;
  if (!isDecimalLike(input.totals.debit)) return null;
  if (!isDecimalLike(input.totals.credit)) return null;
  if (!isDecimalLike(input.closing_balance)) return null;

  for (const row of input.rows) {
    if (!isObject(row)) return null;
    if (!isIsoDate(row.date)) return null;
    if (!isString(row.type)) return null;
    if (!isNullableString(row.reference)) return null;
    if (!isNullableString(row.description)) return null;
    if (!isDecimalLike(row.debit)) return null;
    if (!isDecimalLike(row.credit)) return null;
    if (!isDecimalLike(row.running_balance)) return null;
    if (!isNullableString(row.currency_code)) return null;
    if (!isNullableString(row.exchange_rate)) return null;
    if (!isNullableDecimal(row.foreign_amount)) return null;
  }

  return input as CustomerStatementResponse;
}

export function parseReceivables(input: unknown): ReceivablesResponse | null {
  if (!isObject(input)) return null;
  if (!isIsoDate(input.as_of)) return null;
  if (!validateBaseCurrency(input.base_currency)) return null;
  if (!Array.isArray(input.rows)) return null;
  if (!isNumber(input.count)) return null;
  if (!isObject(input.totals)) return null;
  if (!isDecimalLike(input.totals.grand_total)) return null;
  if (!isDecimalLike(input.totals.paid_total)) return null;
  if (!isDecimalLike(input.totals.balance_due)) return null;

  for (const row of input.rows) {
    if (!isObject(row)) return null;
    if (!isNumber(row.invoice_id)) return null;
    if (!isNullableString(row.invoice_number)) return null;
    if (!isNullableIsoDate(row.invoice_date)) return null;
    if (!isNullableIsoDate(row.due_date)) return null;
    if (!validateCounterpartySummary(row.customer)) return null;
    if (!isDecimalLike(row.grand_total)) return null;
    if (!isDecimalLike(row.paid_total)) return null;
    if (!isDecimalLike(row.balance_due)) return null;
    if (!isNullableNumber(row.days_past_due)) return null;
    if (!isNullableString(row.currency_code)) return null;
    if (!isNullableString(row.exchange_rate)) return null;
    if (!isNullableDecimal(row.base_grand_total)) return null;
  }

  return input as ReceivablesResponse;
}

export function parseReceivablesAging(input: unknown): ReceivablesAgingResponse | null {
  if (!isObject(input)) return null;
  if (!isIsoDate(input.as_of)) return null;
  if (!validateBaseCurrency(input.base_currency)) return null;
  if (!Array.isArray(input.buckets)) return null;
  if (!Array.isArray(input.rows)) return null;
  if (!isObject(input.totals)) return null;
  if (!isObject(input.totals.by_bucket)) return null;
  if (!isDecimalLike(input.totals.total)) return null;

  for (const bucket of input.buckets) {
    if (!isObject(bucket)) return null;
    if (!isString(bucket.key)) return null;
    if (!isString(bucket.label)) return null;
    if (!isNullableNumber(bucket.min)) return null;
    if (!isNullableNumber(bucket.max)) return null;
    if (!isDecimalLike(bucket.total)) return null;
  }

  for (const row of input.rows) {
    if (!isObject(row)) return null;
    if (!validateCounterpartySummary(row.counterparty)) return null;
    if (!isObject(row.buckets)) return null;
    if (!isDecimalLike(row.total)) return null;

    for (const value of Object.values(row.buckets)) {
      if (!isDecimalLike(value)) return null;
    }
  }

  for (const value of Object.values(input.totals.by_bucket)) {
    if (!isDecimalLike(value)) return null;
  }

  return input as ReceivablesAgingResponse;
}

export function parseSupplierStatement(input: unknown): SupplierStatementResponse | null {
  if (!isObject(input)) return null;
  if (!validateCounterpartySummary(input.supplier)) return null;
  if (!validatePeriod(input.period)) return null;
  if (!validateBaseCurrency(input.base_currency)) return null;
  if (!isDecimalLike(input.opening_balance)) return null;
  if (!Array.isArray(input.rows)) return null;
  if (!isObject(input.totals)) return null;
  if (!isDecimalLike(input.totals.debit)) return null;
  if (!isDecimalLike(input.totals.credit)) return null;
  if (!isDecimalLike(input.closing_balance)) return null;

  for (const row of input.rows) {
    if (!isObject(row)) return null;
    if (!isIsoDate(row.date)) return null;
    if (!isString(row.type)) return null;
    if (!isNullableString(row.reference)) return null;
    if (!isNullableString(row.description)) return null;
    if (!isDecimalLike(row.debit)) return null;
    if (!isDecimalLike(row.credit)) return null;
    if (!isDecimalLike(row.running_balance)) return null;
    if (!isNullableString(row.currency_code)) return null;
    if (!isNullableString(row.exchange_rate)) return null;
    if (!isNullableDecimal(row.foreign_amount)) return null;
  }

  return input as SupplierStatementResponse;
}

export function parsePayables(input: unknown): PayablesResponse | null {
  if (!isObject(input)) return null;
  if (!isIsoDate(input.as_of)) return null;
  if (!validateBaseCurrency(input.base_currency)) return null;
  if (!Array.isArray(input.rows)) return null;
  if (!isNumber(input.count)) return null;
  if (!isObject(input.totals)) return null;
  if (!isDecimalLike(input.totals.grand_total)) return null;
  if (!isDecimalLike(input.totals.paid_total)) return null;
  if (!isDecimalLike(input.totals.balance_due)) return null;

  for (const row of input.rows) {
    if (!isObject(row)) return null;
    if (!isNumber(row.bill_id)) return null;
    if (!isNullableString(row.bill_number)) return null;
    if (!isNullableIsoDate(row.bill_date)) return null;
    if (!isNullableIsoDate(row.due_date)) return null;
    if (!validateCounterpartySummary(row.supplier)) return null;
    if (!isDecimalLike(row.grand_total)) return null;
    if (!isDecimalLike(row.paid_total)) return null;
    if (!isDecimalLike(row.balance_due)) return null;
    if (!isNullableNumber(row.days_past_due)) return null;
    if (!isNullableString(row.currency_code)) return null;
    if (!isNullableString(row.exchange_rate)) return null;
    if (!isNullableDecimal(row.base_grand_total)) return null;
  }

  return input as PayablesResponse;
}

export function parsePayablesAging(input: unknown): PayablesAgingResponse | null {
  if (!isObject(input)) return null;
  if (!isIsoDate(input.as_of)) return null;
  if (!validateBaseCurrency(input.base_currency)) return null;
  if (!Array.isArray(input.buckets)) return null;
  if (!Array.isArray(input.rows)) return null;
  if (!isObject(input.totals)) return null;
  if (!isObject(input.totals.by_bucket)) return null;
  if (!isDecimalLike(input.totals.total)) return null;

  for (const bucket of input.buckets) {
    if (!isObject(bucket)) return null;
    if (!isString(bucket.key)) return null;
    if (!isString(bucket.label)) return null;
    if (!isNullableNumber(bucket.min)) return null;
    if (!isNullableNumber(bucket.max)) return null;
    if (!isDecimalLike(bucket.total)) return null;
  }

  for (const row of input.rows) {
    if (!isObject(row)) return null;
    if (!validateCounterpartySummary(row.counterparty)) return null;
    if (!isObject(row.buckets)) return null;
    if (!isDecimalLike(row.total)) return null;

    for (const value of Object.values(row.buckets)) {
      if (!isDecimalLike(value)) return null;
    }
  }

  for (const value of Object.values(input.totals.by_bucket)) {
    if (!isDecimalLike(value)) return null;
  }

  return input as PayablesAgingResponse;
}
