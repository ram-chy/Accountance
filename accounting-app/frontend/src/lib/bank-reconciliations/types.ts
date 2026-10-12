/**
 * Bank-reconciliation data contracts.
 *
 * These mirror the verified Laravel `BankReconciliationResource`,
 * `BankReconciliationMovementResource` and the `bank-reconciliations` routes
 * (see `BankReconciliationController.php`, `BankReconciliationService.php`,
 * `BankReconciliationMovementService.php`, `BankReconciliationCalculator.php`
 * and `FRONTEND_API_HANDOFF.md` §11).
 *
 * Every monetary figure the backend exposes is a decimal *string* produced by
 * `Money::__toString()`; the UI formats those strings directly and never
 * round-trips them through `Number` (see `lib/format/money.ts`). The
 * authoritative `difference` and `reconciled_balance` are read from the
 * resource as-is — the frontend never recomputes a reconciliation balance.
 *
 * `bank_account_id` is the `bank_accounts.id` column. No read endpoint exposes
 * that identifier, so the browser cannot present a bank-account picker or a
 * bank-account filter; the ledger `account_id` is exposed and used instead to
 * label each row (see the Phase 9 report, "known limitations").
 */

export type BankReconciliationStatusValue = "DRAFT" | "IN_PROGRESS" | "RECONCILED";

export const BANK_RECONCILIATION_STATUS_VALUES: readonly BankReconciliationStatusValue[] = [
  "DRAFT",
  "IN_PROGRESS",
  "RECONCILED",
] as const;

export function bankReconciliationStatusLabel(value: BankReconciliationStatusValue): string {
  switch (value) {
    case "DRAFT":
      return "Draft";
    case "IN_PROGRESS":
      return "In progress";
    case "RECONCILED":
      return "Reconciled";
  }
}

/**
 * DRAFT and IN_PROGRESS are the editable states (`BankReconciliationStatus::isEditable`).
 * A reconciliation whose status is unrecognised is treated as not editable.
 */
export function isEditableBankReconciliationStatus(value: BankReconciliationStatusValue): boolean {
  return value === "DRAFT" || value === "IN_PROGRESS";
}

/** One row from the verified `BankReconciliationResource`. */
export type BankReconciliation = {
  id: number;
  company_id: number | null;
  /** `bank_accounts.id`; not resolvable to a label in the browser. */
  bank_account_id: number | null;
  /** The ledger account the statement is reconciled against. */
  account_id: number | null;
  /** Inclusive period start, `Y-m-d`. */
  from_date: string;
  /** Inclusive period end, `Y-m-d`. */
  to_date: string;
  status: BankReconciliationStatusValue;
  statement_opening_balance: string | null;
  statement_closing_balance: string | null;
  ledger_opening: string | null;
  ledger_closing: string | null;
  period_debits: string | null;
  period_credits: string | null;
  cleared_debits: string | null;
  cleared_credits: string | null;
  uncleared_debits: string | null;
  uncleared_credits: string | null;
  /** Authoritative: `ledger_closing - uncleared_debits + uncleared_credits`. */
  reconciled_balance: string | null;
  /** Authoritative: `statement_closing - reconciled_balance`. */
  difference: string | null;
  total_movements: number;
  cleared_movements: number;
  uncleared_movements: number;
  is_reconciled: boolean;
  /** Closing statement balance of the immediately preceding settled reconciliation. */
  previous_reconciled_closing: string | null;
  /** `statement_opening - previous_reconciled_closing`; a warning, never an error. */
  opening_balance_difference: string | null;
  notes: string | null;
  completed_at: string | null;
  reopened_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

/** Movement direction as derived by `BankReconciliationMovementResource`. */
export type MovementDirectionValue = "DEBIT" | "CREDIT" | "ZERO";

export const MOVEMENT_DIRECTION_VALUES: readonly MovementDirectionValue[] = [
  "DEBIT",
  "CREDIT",
  "ZERO",
] as const;

/** One posted ledger line from the verified `BankReconciliationMovementResource`. */
export type BankReconciliationMovement = {
  journal_id: number | null;
  journal_line_id: number;
  journal_number: string | null;
  journal_date: string | null;
  reference: string | null;
  description: string | null;
  source_type: string | null;
  source_id: number | null;
  debit: string | null;
  credit: string | null;
  amount: string | null;
  direction: MovementDirectionValue;
  /** True when this line is an item of the current reconciliation. */
  cleared: boolean;
};

/** Server-side list filters the backend actually supports. */
export type BankReconciliationFilters = {
  status: BankReconciliationStatusValue | "";
  /** Inclusive lower bound on the reconciliation period, `Y-m-d`. */
  from_date: string;
  /** Inclusive upper bound on the reconciliation period, `Y-m-d`. */
  to_date: string;
};

export const DEFAULT_BANK_RECONCILIATION_FILTERS: BankReconciliationFilters = {
  status: "",
  from_date: "",
  to_date: "",
};

/** Snapshot of a list page, with the backend's flat-array pagination caveat. */
export type BankReconciliationListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: BankReconciliation[];
  hasMore: boolean;
  perPage: number;
  message?: string;
  requestId?: string;
};

/** The read state of a single reconciliation detail. */
export type BankReconciliationDetailState =
  | { status: "idle" }
  | { status: "loading" }
  | { status: "ready"; data: BankReconciliation }
  | { status: "not-found" }
  | { status: "error"; message: string; requestId?: string };

/** The read state of a reconciliation's eligible movements. */
export type BankReconciliationMovementsState =
  | { status: "idle" }
  | { status: "loading" }
  | { status: "ready"; items: BankReconciliationMovement[] }
  | { status: "not-found" }
  | { status: "error"; message: string; requestId?: string };

/**
 * The add-item body validated by `AddBankReconciliationItemRequest`. Only the
 * journal line identifier and optional notes are client-supplied; company and
 * reconciliation scope come from the route and the server-side company context.
 */
export type AddBankReconciliationItemPayload = {
  journal_line_id: number;
  notes?: string | null;
};
