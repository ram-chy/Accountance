/**
 * Cash & Bank report types.
 *
 * `CashBankReportService::generate()` delegates to
 * `GeneralLedgerReportService::generate()`, so the response is the general
 * ledger shape verbatim: opening balance, dated movements with a running
 * balance, period totals and a closing balance. The types are aliases of the
 * general-ledger ones rather than a re-declared copy, so the two cannot drift.
 *
 * The report is unrestricted on account type at the backend; the account is
 * still required, and the UI offers the company's classified cash/bank accounts.
 */

import type {
  GeneralLedgerFilters,
  GeneralLedgerResponse,
  GeneralLedgerState,
} from "./general-ledger-types";

export type CashBankResponse = GeneralLedgerResponse;

export type CashBankFilters = GeneralLedgerFilters;

export const DEFAULT_CASH_BANK_FILTERS: CashBankFilters = {
  account_id: null,
  from: "",
  to: "",
};

export type CashBankState = GeneralLedgerState;
