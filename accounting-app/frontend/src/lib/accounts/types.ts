/**
 * Chart of accounts data contracts.
 *
 * These mirror the verified Laravel `AccountResource` and the account routes
 * exactly (see `AccountController.php` / `AccountResource.php` and
 * `FRONTEND_API_HANDOFF.md` §6 "Chart of accounts"). Money does not appear here
 * — the account endpoints carry classification and lifecycle data only; balances
 * live on the ledger endpoints, which this phase does not render.
 *
 * Payloads for `POST /accounts` and `PUT /accounts/{account}` are written in
 * the snake_case the backend validates. Identifiers, `is_active`, `is_system`
 * and `has_journal_history` are server-owned and never sent by the browser.
 */

export type AccountTypeValue =
  | "ASSET"
  | "LIABILITY"
  | "EQUITY"
  | "REVENUE"
  | "EXPENSE";

export type NormalBalanceValue = "DEBIT" | "CREDIT";

export type CashBankKindValue = "CASH" | "BANK";

export const ACCOUNT_TYPE_VALUES: readonly AccountTypeValue[] = [
  "ASSET",
  "LIABILITY",
  "EQUITY",
  "REVENUE",
  "EXPENSE",
] as const;

export const NORMAL_BALANCE_VALUES: readonly NormalBalanceValue[] = [
  "DEBIT",
  "CREDIT",
] as const;

export const CASH_BANK_KIND_VALUES: readonly CashBankKindValue[] = [
  "CASH",
  "BANK",
] as const;

/**
 * Fallback normal-balance mapping, mirroring `AccountType::normalBalance()`.
 *
 * Used only for the form's helper hint before `GET /accounts/types` responds;
 * the verified endpoint is the source of truth at runtime.
 */
export const TYPE_NORMAL_BALANCE: Record<AccountTypeValue, NormalBalanceValue> = {
  ASSET: "DEBIT",
  EXPENSE: "DEBIT",
  LIABILITY: "CREDIT",
  EQUITY: "CREDIT",
  REVENUE: "CREDIT",
};

/** One row from the verified `AccountResource`. */
export type Account = {
  id: number;
  code: string;
  name: string;
  account_type: AccountTypeValue;
  /** null for an ordinary account; CASH or BANK when classified. */
  cash_bank_kind: CashBankKindValue | null;
  is_cash_bank_account: boolean;
  /** Effective normal balance: the contra override, or the type's default. */
  normal_balance: NormalBalanceValue;
  /** True when `normal_balance` was explicitly overridden against the type. */
  is_contra: boolean;
  parent_id: number | null;
  description: string | null;
  is_active: boolean;
  is_system: boolean;
  has_journal_history: boolean;
  created_at: string | null;
  updated_at: string | null;
};

/** `GET /api/accounts/types` entry: the list of valid types and their balance. */
export type AccountTypeMeta = {
  value: AccountTypeValue;
  normal_balance: NormalBalanceValue;
};

/** Query state the list screen mirrors to the verified backend filters. */
export type AccountListFilters = {
  search: string;
  account_type: AccountTypeValue | "";
  /** three-state: "" = all, true = active, false = inactive */
  is_active: "" | "active" | "inactive";
};

/** Defaults for the list filters. */
export const DEFAULT_ACCOUNT_FILTERS: AccountListFilters = {
  search: "",
  account_type: "",
  is_active: "",
};

/** Snapshot of a list page, with the backend's flat-array pagination caveat. */
export type AccountListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: Account[];
  hasMore: boolean;
  perPage: number;
  message?: string;
  requestId?: string;
};

/**
 * Request/response shapes for account write routes.
 *
 * `normal_balance` is the optional contra override (null = follow the type).
 * `cash_bank_kind` is the optional cash/bank classification.
 * `parent_id` is intentionally omitted from the form in this phase — see the
 * Phase 5 report for the pagination-completeness reason.
 */
export type AccountWritePayload = {
  code: string;
  name: string;
  account_type: AccountTypeValue;
  cash_bank_kind: CashBankKindValue | null;
  normal_balance: NormalBalanceValue | null;
  description: string | null;
};