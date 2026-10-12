/**
 * Runtime validation for dashboard responses.
 *
 * The dashboard consumes several endpoints whose payloads are untrusted input.
 * Each parser narrows the raw JSON to the exact fields the UI uses, throwing a
 * plain `DashboardDataError` when a required monetary field is missing or
 * malformed. Optional collections (account rows, activity rows) are filtered
 * rather than fatal: one bad row must not blank an otherwise valid report.
 */

import { normalizeList } from "@/lib/api/pagination";
import { isDecimalString } from "@/lib/format/money";

import type {
  AccountAmountRow,
  AccountBalance,
  CashBankAccount,
  CurrencyInfo,
  JournalActivity,
  ProfitLossReport,
  SettlementTotalsReport,
} from "./types";

export class DashboardDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "DashboardDataError";
  }
}

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function asDecimal(value: unknown, field: string): string {
  if (typeof value === "string" && isDecimalString(value)) {
    return value;
  }

  if (typeof value === "number" && Number.isFinite(value)) {
    // The backend sends strings; a number is coerced defensively rather than
    // rejected outright, but it still never becomes a floating-point sum.
    return String(value);
  }

  throw new DashboardDataError(`Expected a decimal amount for "${field}".`);
}

function asString(value: unknown, fallback = ""): string {
  return typeof value === "string" ? value : fallback;
}

function asNullableString(value: unknown): string | null {
  return typeof value === "string" ? value : null;
}

function asNumber(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

/** Parse the base-currency disclosure block; unknown fields become null. */
export function parseCurrency(value: unknown): CurrencyInfo | null {
  if (!isRecord(value)) {
    return null;
  }

  return {
    code: asNullableString(value.code),
    name: asNullableString(value.name),
    symbol: asNullableString(value.symbol),
    decimals: asNumber(value.decimals),
  };
}

function parseAccountRows(value: unknown): AccountAmountRow[] {
  if (!Array.isArray(value)) {
    return [];
  }

  const rows: AccountAmountRow[] = [];

  for (const entry of value) {
    if (!isRecord(entry)) {
      continue;
    }

    const accountId = asNumber(entry.account_id);

    if (accountId === null || !isDecimalString(entry.amount)) {
      continue;
    }

    rows.push({
      accountId,
      code: asString(entry.code, "—"),
      name: asString(entry.name, "Unnamed account"),
      amount: entry.amount as string,
    });
  }

  return rows;
}

export function parseProfitLoss(raw: unknown): ProfitLossReport {
  if (!isRecord(raw) || !isRecord(raw.revenue) || !isRecord(raw.expenses)) {
    throw new DashboardDataError("The profit and loss report is malformed.");
  }

  return {
    period: isRecord(raw.period)
      ? {
          from: asNullableString(raw.period.from),
          to: asNullableString(raw.period.to),
        }
      : { from: null, to: null },
    baseCurrency: parseCurrency(raw.base_currency) ?? {
      code: null,
      name: null,
      symbol: null,
      decimals: null,
    },
    revenue: {
      total: asDecimal(raw.revenue.total, "revenue.total"),
      accounts: parseAccountRows(raw.revenue.accounts),
    },
    expenses: {
      total: asDecimal(raw.expenses.total, "expenses.total"),
      accounts: parseAccountRows(raw.expenses.accounts),
    },
    grossProfit: asDecimal(raw.gross_profit, "gross_profit"),
    netProfit: asDecimal(raw.net_profit, "net_profit"),
    isProfitable: raw.is_profitable === true,
  };
}

function parseSettlementReport(raw: unknown, label: string): SettlementTotalsReport {
  if (!isRecord(raw) || !isRecord(raw.totals)) {
    throw new DashboardDataError(`The ${label} report is malformed.`);
  }

  return {
    asOf: asString(raw.as_of),
    baseCurrency: parseCurrency(raw.base_currency) ?? {
      code: null,
      name: null,
      symbol: null,
      decimals: null,
    },
    count: asNumber(raw.count) ?? 0,
    balanceDue: asDecimal(raw.totals.balance_due, "totals.balance_due"),
  };
}

export function parseReceivables(raw: unknown): SettlementTotalsReport {
  return parseSettlementReport(raw, "receivables");
}

export function parsePayables(raw: unknown): SettlementTotalsReport {
  return parseSettlementReport(raw, "payables");
}

export function parseAccountBalance(raw: unknown): AccountBalance {
  if (!isRecord(raw)) {
    throw new DashboardDataError("The account balance is malformed.");
  }

  const accountId = asNumber(raw.account_id);

  if (accountId === null) {
    throw new DashboardDataError("The account balance is missing its account.");
  }

  return {
    accountId,
    code: asString(raw.code, "—"),
    name: asString(raw.name, "Unnamed account"),
    balance: asDecimal(raw.balance, "balance"),
  };
}

export function parseCashBankAccounts(raw: unknown): CashBankAccount[] {
  const { items } = normalizeList<unknown>(raw);

  const accounts: CashBankAccount[] = [];

  for (const entry of items) {
    if (!isRecord(entry)) {
      continue;
    }

    const id = asNumber(entry.id);

    if (id === null) {
      continue;
    }

    accounts.push({
      id,
      code: asString(entry.code, "—"),
      name: asString(entry.name, "Unnamed account"),
      kind: asNullableString(entry.cash_bank_kind),
      isActive: entry.is_active === true,
    });
  }

  return accounts;
}

export function parseJournals(raw: unknown): JournalActivity[] {
  const { items } = normalizeList<unknown>(raw);

  const rows: JournalActivity[] = [];

  for (const entry of items) {
    if (!isRecord(entry)) {
      continue;
    }

    const id = asNumber(entry.id);

    if (id === null) {
      continue;
    }

    const totals = isRecord(entry.totals) ? entry.totals : null;
    const amount = totals && isDecimalString(totals.total_debit) ? totals.total_debit : null;

    rows.push({
      id,
      number: asString(entry.journal_number, "—"),
      date: asNullableString(entry.journal_date),
      description: asNullableString(entry.description),
      reference: asNullableString(entry.reference),
      status: asString(entry.status, "UNKNOWN"),
      sourceType: asNullableString(entry.source_type),
      amount,
    });
  }

  return rows;
}
