/**
 * Runtime validation for bank-reconciliation responses and inputs.
 *
 * API JSON is untrusted input. The parsers below narrow a raw payload to the
 * exact fields the UI renders, applying the same "filter the invalid row, never
 * fabricate" strategy as the account, journal and period validators.
 *
 * A reconciliation missing its identifier, a known status or either period date
 * is not safe to render and is skipped. Unknown status values are rejected
 * outright rather than guessed at, so an unrecognised lifecycle state can never
 * be rendered as open or completed. Monetary fields are narrowed to
 * `string | null`: a missing figure renders as an em dash rather than a made-up
 * zero, and no arithmetic is performed on them here.
 */

import { normalizeList } from "@/lib/api/pagination";

import {
  BANK_RECONCILIATION_STATUS_VALUES,
  MOVEMENT_DIRECTION_VALUES,
  type AddBankReconciliationItemPayload,
  type BankReconciliation,
  type BankReconciliationFilters,
  type BankReconciliationListState,
  type BankReconciliationMovement,
  type BankReconciliationMovementsState,
  type BankReconciliationStatusValue,
  type MovementDirectionValue,
} from "./types";

export class BankReconciliationsDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "BankReconciliationsDataError";
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

function asCount(value: unknown): number {
  return typeof value === "number" && Number.isFinite(value) && value >= 0 ? value : 0;
}

function asBoolean(value: unknown): boolean | undefined {
  return typeof value === "boolean" ? value : undefined;
}

function asStatus(value: unknown): BankReconciliationStatusValue | undefined {
  return BANK_RECONCILIATION_STATUS_VALUES.includes(value as BankReconciliationStatusValue)
    ? (value as BankReconciliationStatusValue)
    : undefined;
}

function asDirection(value: unknown): MovementDirectionValue | undefined {
  return MOVEMENT_DIRECTION_VALUES.includes(value as MovementDirectionValue)
    ? (value as MovementDirectionValue)
    : undefined;
}

/**
 * Narrow one reconciliation row, or return null when it is not safe to render.
 *
 * Required: `id`, a known `status` and both inclusive period dates. The
 * monetary figures and audit timestamps degrade to null when absent or
 * malformed so a single missing derived field never blanks the row.
 */
export function parseBankReconciliation(value: unknown): BankReconciliation | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = asNumber(value.id);
  const status = asStatus(value.status);
  const from_date = asString(value.from_date);
  const to_date = asString(value.to_date);

  if (id === undefined || status === undefined || from_date === undefined || to_date === undefined) {
    return null;
  }

  return {
    id,
    company_id: asNullableNumber(value.company_id),
    bank_account_id: asNullableNumber(value.bank_account_id),
    account_id: asNullableNumber(value.account_id),
    from_date,
    to_date,
    status,
    statement_opening_balance: asNullableString(value.statement_opening_balance),
    statement_closing_balance: asNullableString(value.statement_closing_balance),
    ledger_opening: asNullableString(value.ledger_opening),
    ledger_closing: asNullableString(value.ledger_closing),
    period_debits: asNullableString(value.period_debits),
    period_credits: asNullableString(value.period_credits),
    cleared_debits: asNullableString(value.cleared_debits),
    cleared_credits: asNullableString(value.cleared_credits),
    uncleared_debits: asNullableString(value.uncleared_debits),
    uncleared_credits: asNullableString(value.uncleared_credits),
    reconciled_balance: asNullableString(value.reconciled_balance),
    difference: asNullableString(value.difference),
    total_movements: asCount(value.total_movements),
    cleared_movements: asCount(value.cleared_movements),
    uncleared_movements: asCount(value.uncleared_movements),
    is_reconciled: asBoolean(value.is_reconciled) ?? status === "RECONCILED",
    previous_reconciled_closing: asNullableString(value.previous_reconciled_closing),
    opening_balance_difference: asNullableString(value.opening_balance_difference),
    notes: asNullableString(value.notes),
    completed_at: asNullableString(value.completed_at),
    reopened_at: asNullableString(value.reopened_at),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toListError(message: string): BankReconciliationListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

/**
 * Narrow the flat (or future paginator-shaped) list payload into a validated
 * list state. Invalid rows are skipped, never fabricated.
 */
export function parseBankReconciliationList(
  raw: unknown,
  perPage: number,
): BankReconciliationListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toListError("The bank reconciliations response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: BankReconciliation[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const reconciliation = parseBankReconciliation(entry);

    if (reconciliation === null) {
      skipped += 1;
      continue;
    }

    items.push(reconciliation);
  }

  if (skipped > 0 && items.length === 0) {
    return toListError("The bank reconciliations response contained no valid rows.");
  }

  return { status: "ready", items, perPage, hasMore: list.hasMore };
}

/** Narrow a single write-response payload, or throw when malformed. */
export function parseBankReconciliationOrThrow(
  raw: unknown,
  label = "bank reconciliation",
): BankReconciliation {
  const reconciliation = parseBankReconciliation(raw);

  if (reconciliation === null) {
    throw new BankReconciliationsDataError(`The ${label} response was invalid.`);
  }

  return reconciliation;
}

/**
 * Narrow one movement row, or return null when it is not safe to render.
 *
 * Required: `journal_line_id`, a known `direction` and the `cleared` flag.
 */
export function parseBankReconciliationMovement(
  value: unknown,
): BankReconciliationMovement | null {
  if (!isRecord(value)) {
    return null;
  }

  const journal_line_id = asNumber(value.journal_line_id);
  const direction = asDirection(value.direction);
  const cleared = asBoolean(value.cleared);

  if (journal_line_id === undefined || direction === undefined || cleared === undefined) {
    return null;
  }

  return {
    journal_id: asNullableNumber(value.journal_id),
    journal_line_id,
    journal_number: asNullableString(value.journal_number),
    journal_date: asNullableString(value.journal_date),
    reference: asNullableString(value.reference),
    description: asNullableString(value.description),
    source_type: asNullableString(value.source_type),
    source_id: asNullableNumber(value.source_id),
    debit: asNullableString(value.debit),
    credit: asNullableString(value.credit),
    amount: asNullableString(value.amount),
    direction,
    cleared,
  };
}

/**
 * Narrow the movements payload into a validated state. Invalid rows are
 * skipped; a payload that is not a list is an error.
 */
export function parseBankReconciliationMovements(
  raw: unknown,
): BankReconciliationMovementsState {
  if (Array.isArray(raw)) {
    return { status: "ready", items: narrowMovements(raw) };
  }

  if (isRecord(raw) && Array.isArray(raw.data)) {
    return { status: "ready", items: narrowMovements(raw.data) };
  }

  return { status: "error", message: "The movements response was not a list." };
}

function narrowMovements(entries: unknown[]): BankReconciliationMovement[] {
  const items: BankReconciliationMovement[] = [];

  for (const entry of entries) {
    const movement = parseBankReconciliationMovement(entry);

    if (movement !== null) {
      items.push(movement);
    }
  }

  return items;
}

export type BankReconciliationValidationErrors = Record<string, string>;

/**
 * Client-side validation mirroring `AddBankReconciliationItemRequest`. Feedback
 * only — the backend re-validates every write and may refuse a movement that is
 * not eligible for the reconciliation's account or period.
 */
export function validateAddReconciliationItem(
  payload: AddBankReconciliationItemPayload,
): BankReconciliationValidationErrors {
  const errors: BankReconciliationValidationErrors = {};

  if (!Number.isInteger(payload.journal_line_id) || payload.journal_line_id <= 0) {
    errors.journal_line_id = "Select a movement to clear.";
  }

  if (payload.notes != null && payload.notes.length > 5000) {
    errors.notes = "The notes may not be longer than 5000 characters.";
  }

  return errors;
}

/**
 * Extract the created item id from an add-item response.
 *
 * `BankReconciliationController::storeItem` returns a bare `{ "id": <item id> }`.
 * That id is the only way to target the item for removal, because no read
 * endpoint exposes it. The response is validated rather than trusted.
 */
export function parseBankReconciliationItemId(raw: unknown): number | null {
  if (!isRecord(raw)) {
    return null;
  }

  const id = asNumber(raw.id);
  return id !== undefined ? id : null;
}

/** True when a decimal string represents exactly zero. Never uses a float. */
export function isZeroDecimalString(value: string | null | undefined): boolean {
  if (typeof value !== "string" || !/^[+-]?\d+(?:\.\d+)?$/.test(value.trim())) {
    return false;
  }

  const digits = value.trim().replace(/^[+-]/, "").replace(/[.0]/g, "");
  return digits.length === 0;
}

/** A short human label for a movement direction. */
export function movementDirectionLabel(value: MovementDirectionValue): string {
  switch (value) {
    case "DEBIT":
      return "Debit";
    case "CREDIT":
      return "Credit";
    case "ZERO":
      return "Zero";
  }
}

/**
 * Read the distinct bank-account ids present in a set of loaded reconciliations.
 * Used only to recognise repeated ids in already-loaded rows; it is never a
 * substitute for the unexposed bank-account list.
 */
export function distinctBankAccountIds(items: BankReconciliation[]): number[] {
  const seen = new Set<number>();

  for (const item of items) {
    if (item.bank_account_id !== null) {
      seen.add(item.bank_account_id);
    }
  }

  return [...seen];
}

/** Type guard for the filter shape, used by tests and the view. */
export function normalizeFilters(
  filters: Partial<BankReconciliationFilters>,
): BankReconciliationFilters {
  return {
    status: asStatus(filters.status) ?? "",
    from_date: typeof filters.from_date === "string" ? filters.from_date : "",
    to_date: typeof filters.to_date === "string" ? filters.to_date : "",
  };
}
