/**
 * General Ledger report types.
 */

import type { BaseCurrency, ReportPeriod } from "./types";

export type GeneralLedgerRow = {
  journal_id: number;
  journal_line_id: number;
  journal_number: string | null;
  journal_date: string;
  reference: string | null;
  description: string | null;
  source_type: string;
  source_id: number | null;
  debit: string;
  credit: string;
  running_balance: string;
  currency_id: number | null;
  exchange_rate: string | null;
  foreign_debit: string | null;
  foreign_credit: string | null;
};

export type GeneralLedgerAccount = {
  id: number;
  code: string;
  name: string;
  account_type: string;
  normal_balance: string;
  is_active: boolean;
};

export type GeneralLedgerResponse = {
  account: GeneralLedgerAccount;
  period: ReportPeriod;
  base_currency: BaseCurrency;
  opening_balance: string;
  rows: GeneralLedgerRow[];
  period_debits: string;
  period_credits: string;
  closing_balance: string;
  closing_balance_signed: string;
};

export type GeneralLedgerFilters = {
  account_id: number | null;
  from: string;
  to: string;
};

export const DEFAULT_GENERAL_LEDGER_FILTERS: GeneralLedgerFilters = {
  account_id: null,
  from: "",
  to: "",
};

export type GeneralLedgerState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: GeneralLedgerResponse }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
