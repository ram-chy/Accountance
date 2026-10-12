/**
 * Cash & bank data contracts.
 *
 * These mirror the verified Laravel `CashBankAccountResource` and
 * `CashBankTransactionResource`, the cash/bank routes, and the Phase 4 ledger
 * balance used for the overview (see `CashBankAccountController`,
 * `CashBankTransactionController`, `LedgerController::accountBalance`,
 * `CashBankTransactionService`, `CashBankPostingService` and
 * `FRONTEND_API_HANDOFF.md` §7 "Cash & banking").
 *
 * Money is carried as decimal *strings* — never numbers — because the backend
 * validates and stores decimals as strings and the UI must not re-introduce a
 * double round-trip. No currency field is modelled: the transaction and
 * account resources do not expose one in this phase, so the UI must never
 * invent a currency symbol (see the Phase 7 report).
 *
 * Payloads for the write routes are written in the snake_case the backend
 * validates. Identifiers, `transaction_number`, `status`, `journal_id`,
 * `posted_by`, `posted_at` and all audit columns are server-owned and are never
 * sent by the browser.
 */

export type CashBankKindValue = "CASH" | "BANK";

export const CASH_BANK_KIND_VALUES: readonly CashBankKindValue[] = [
  "CASH",
  "BANK",
] as const;

export type CashBankTransactionTypeValue = "DEPOSIT" | "WITHDRAWAL" | "TRANSFER";

export const CASH_BANK_TRANSACTION_TYPE_VALUES: readonly CashBankTransactionTypeValue[] = [
  "DEPOSIT",
  "WITHDRAWAL",
  "TRANSFER",
] as const;

/**
 * A cash/bank transaction's status is the shared `PaymentStatus` enum, of which
 * only the draft/posted pair is reachable for a money movement.
 */
export type CashBankTransactionStatusValue = "DRAFT" | "POSTED";

export const CASH_BANK_TRANSACTION_STATUS_VALUES: readonly CashBankTransactionStatusValue[] = [
  "DRAFT",
  "POSTED",
] as const;

export function cashBankKindLabel(value: CashBankKindValue): string {
  return value === "CASH" ? "Cash" : "Bank";
}

export function cashBankTransactionTypeLabel(value: CashBankTransactionTypeValue): string {
  switch (value) {
    case "DEPOSIT":
      return "Deposit";
    case "WITHDRAWAL":
      return "Withdrawal";
    case "TRANSFER":
      return "Transfer";
  }
}

export function cashBankTransactionStatusLabel(value: CashBankTransactionStatusValue): string {
  return value === "POSTED" ? "Posted" : "Draft";
}

/** The nested bank details on a `CashBankAccountResource`, or null for cash. */
export type BankAccountDetails = {
  account_name: string;
  bank_name: string;
  account_number: string | null;
  branch: string | null;
  bank_identifier: string | null;
  is_active: boolean;
};

/** One row from the verified `CashBankAccountResource`. */
export type CashBankAccount = {
  id: number;
  code: string;
  name: string;
  account_type: string;
  normal_balance: string;
  /** CASH, BANK, or null (no longer classified). */
  cash_bank_kind: CashBankKindValue | null;
  is_active: boolean;
  description: string | null;
  bank_account: BankAccountDetails | null;
  created_at: string | null;
  updated_at: string | null;
};

/** Client-side kind filter; empty means "both cash and bank". */
export type CashBankAccountFilters = {
  kind: CashBankKindValue | "";
};

export const DEFAULT_CASH_BANK_ACCOUNT_FILTERS: CashBankAccountFilters = {
  kind: "",
};

/** A single account balance from `GET /accounting/accounts/{account}/balance`. */
export type AccountBalance = {
  account_id: number;
  code: string;
  name: string;
  account_type: string;
  normal_balance: string;
  is_active: boolean;
  total_debit: string;
  total_credit: string;
  /** Signed on the account's normal side. */
  balance: string;
};

/**
 * Validated account list state, mirroring `AccountListState`. `perPage` is
 * carried so the flat-array pagination caveat can be recognised.
 */
export type CashBankAccountListState =
  | { status: "loading"; items: []; perPage: number; hasMore: false }
  | { status: "unavailable"; items: []; perPage: number; hasMore: false; message: string }
  | {
      status: "error";
      items: [];
      perPage: number;
      hasMore: false;
      message: string;
      requestId?: string;
    }
  | { status: "ready"; items: CashBankAccount[]; perPage: number; hasMore: boolean };

/**
 * A balance fetch is per account and best-effort: the overview still renders
 * when the ledger permission is absent or a single balance call fails. The state
 * union lives with the hook that produces it (`use-account-balance.ts`).
 */

/** One account reference attached to a transaction (a full AccountResource). */
export type TransactionAccountRef = {
  id: number;
  code: string;
  name: string;
};

/** One row from the verified `CashBankTransactionResource`. */
export type CashBankTransaction = {
  id: number;
  transaction_number: string;
  transaction_type: CashBankTransactionTypeValue;
  /** Date-only business date (`YYYY-MM-DD`). */
  transaction_date: string;
  source_account_id: number;
  destination_account_id: number;
  amount: string;
  status: CashBankTransactionStatusValue;
  reference: string | null;
  notes: string | null;
  source_account: TransactionAccountRef | null;
  destination_account: TransactionAccountRef | null;
  journal_id: number | null;
  posted_at: string | null;
  created_by: number | null;
  posted_by: number | null;
  created_at: string | null;
  updated_at: string | null;
};

/** Query state the transactions screen mirrors to the verified filters. */
export type CashBankTransactionFilters = {
  transaction_type: CashBankTransactionTypeValue | "";
  status: CashBankTransactionStatusValue | "";
  /** Account id matched against either side; "" means all accounts. */
  account_id: string;
  from: string;
  to: string;
  reference: string;
};

export const DEFAULT_CASH_BANK_TRANSACTION_FILTERS: CashBankTransactionFilters = {
  transaction_type: "",
  status: "",
  account_id: "",
  from: "",
  to: "",
  reference: "",
};

/** Validated transaction list state, mirroring `JournalListState`. */
export type CashBankTransactionListState =
  | { status: "loading"; items: []; perPage: number; hasMore: false }
  | { status: "unavailable"; items: []; perPage: number; hasMore: false; message: string }
  | {
      status: "error";
      items: [];
      perPage: number;
      hasMore: false;
      message: string;
      requestId?: string;
    }
  | { status: "ready"; items: CashBankTransaction[]; hasMore: boolean; perPage: number };

/** The validated create/update body. `transaction_type` is never sent. */
export type CashBankTransactionWritePayload = {
  transaction_date: string;
  amount: string;
  source_account_id: number;
  destination_account_id: number;
  reference: string | null;
  notes: string | null;
};

/** The classification / bank-details update body for an existing account. */
export type CashBankAccountClassifyPayload = {
  cash_bank_kind?: CashBankKindValue | null;
  bank_account?: {
    account_name: string;
    bank_name: string;
    account_number?: string | null;
    branch?: string | null;
    bank_identifier?: string | null;
  } | null;
};
