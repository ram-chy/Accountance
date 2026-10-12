/**
 * Runtime validation for journal responses and inputs.
 *
 * API JSON is untrusted input. The parsers below narrow a raw payload to the
 * exact fields the UI renders. A journal missing a required identifier, number,
 * date or a known status is treated as invalid and skipped — the same
 * "filter the invalid row, never fabricate" strategy the dashboard and account
 * validators use. Invalid *lines* are skipped individually so one bad row cannot
 * hide an otherwise valid journal. Write payloads are checked against the
 * backend's own rules for immediate feedback; the backend remains the validator
 * of record.
 */

import { normalizeList } from "@/lib/api/pagination";

import type {
  Journal,
  JournalLine,
  JournalListState,
  JournalStatusValue,
  JournalSourceValue,
  JournalTotals,
  JournalWritePayload,
} from "./types";
import { JOURNAL_SOURCE_VALUES, JOURNAL_STATUS_VALUES } from "./types";

/** Mirrors `config('accounting.limits.max_lines_per_journal', 500)`. */
export const MAX_JOURNAL_LINES = 500;

/** `date_format:Y-m-d`. */
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/** A decimal string with an unbounded integer part and up to 4 fraction digits. */
const DECIMAL_INPUT_PATTERN = /^\d+(?:\.\d{1,4})?$/;

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

function asStatus(value: unknown): JournalStatusValue | undefined {
  return JOURNAL_STATUS_VALUES.includes(value as JournalStatusValue)
    ? (value as JournalStatusValue)
    : undefined;
}

function asSource(value: unknown): JournalSourceValue | undefined {
  return JOURNAL_SOURCE_VALUES.includes(value as JournalSourceValue)
    ? (value as JournalSourceValue)
    : undefined;
}

/**
 * Narrow one line, or return null when it is not safe to render.
 *
 * `id`, `account_id`, `line_number`, `debit` and `credit` are required — a line
 * with no account or no amount is not a line. Optional text and the foreign
 * fields degrade to null.
 */
export function parseJournalLine(value: unknown): JournalLine | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = asNumber(value.id);
  const line_number = asNumber(value.line_number);
  const account_id = asNumber(value.account_id);
  const debit = asString(value.debit);
  const credit = asString(value.credit);

  if (
    id === undefined ||
    line_number === undefined ||
    account_id === undefined ||
    debit === undefined ||
    credit === undefined
  ) {
    return null;
  }

  return {
    id,
    line_number,
    account_id,
    account_code: asNullableString(value.account_code),
    account_name: asNullableString(value.account_name),
    description: asNullableString(value.description),
    debit,
    credit,
    currency_id: asNullableNumber(value.currency_id),
    currency_code: asNullableString(value.currency_code),
    exchange_rate: asNullableString(value.exchange_rate),
    foreign_debit: asNullableString(value.foreign_debit),
    foreign_credit: asNullableString(value.foreign_credit),
  };
}

function parseTotals(value: unknown): JournalTotals | null {
  if (!isRecord(value)) {
    return null;
  }

  const total_debit = asString(value.total_debit);
  const total_credit = asString(value.total_credit);

  if (total_debit === undefined || total_credit === undefined) {
    return null;
  }

  return {
    total_debit,
    total_credit,
    is_balanced: value.is_balanced === true,
  };
}

/**
 * Narrow one journal row, or return null when it is not safe to render.
 *
 * Required: id, journal_number, journal_date and a known status. Everything else
 * degrades; the lines array is filtered, never fabricated.
 */
export function parseJournal(value: unknown): Journal | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = asNumber(value.id);
  const journal_number = asString(value.journal_number);
  const journal_date = asString(value.journal_date);
  const status = asStatus(value.status);

  if (
    id === undefined ||
    journal_number === undefined ||
    journal_date === undefined ||
    status === undefined
  ) {
    return null;
  }

  const rawLines = Array.isArray(value.lines) ? value.lines : [];
  const lines: JournalLine[] = [];

  for (const entry of rawLines) {
    const line = parseJournalLine(entry);
    if (line !== null) {
      lines.push(line);
    }
  }

  return {
    id,
    journal_number,
    journal_date,
    description: asNullableString(value.description),
    reference: asNullableString(value.reference),
    status,
    source_type: asSource(value.source_type) ?? null,
    source_id: asNullableNumber(value.source_id),
    created_by: asNullableNumber(value.created_by),
    posted_by: asNullableNumber(value.posted_by),
    posted_at: asNullableString(value.posted_at),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
    lines,
    totals: parseTotals(value.totals),
  };
}

function toErrorState(message: string): JournalListState {
  return { status: "error", items: [], perPage: 0, hasMore: false, message };
}

/**
 * Narrow the flat list payload into a validated `JournalListState`. Invalid rows
 * are skipped; `perPage` is supplied by the caller so a short page is recognised
 * correctly for `hasMore`.
 */
export function parseJournalList(raw: unknown, perPage: number): JournalListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toErrorState("The journals response was not a list.");
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: Journal[] = [];
  let skipped = 0;

  for (const entry of list.items) {
    const journal = parseJournal(entry);

    if (journal === null) {
      skipped += 1;
      continue;
    }

    items.push(journal);
  }

  if (skipped > 0 && items.length === 0) {
    return toErrorState("The journals response contained no valid journal rows.");
  }

  return { status: "ready", items, perPage, hasMore: list.hasMore };
}

/** Narrow a single-journal payload for write responses and the detail view. */
export function parseJournalDetail(raw: unknown): Journal | null {
  return parseJournal(raw);
}

export type JournalValidationErrors = Record<string, string>;

/** Parse a decimal string to 4-decimal minor units, or null when malformed. */
function parseScaled(value: string): bigint | null {
  if (!DECIMAL_INPUT_PATTERN.test(value.trim())) {
    return null;
  }

  const [integer, fraction = ""] = value.trim().split(".");
  const padded = fraction.padEnd(4, "0");

  return BigInt(integer) * BigInt(10000) + BigInt(padded);
}

/**
 * Client-side validation mirroring `StoreJournalRequest` / `UpdateJournalRequest`
 * and `ValidatesJournalLines`.
 *
 * Feedback only — the backend re-validates every write. Per-line errors are keyed
 * `lines.{index}.{field}` so the form can attach them to the right control; a
 * whole-journal problem (line count, balance) is keyed `lines`.
 */
export function validateJournalWrite(
  payload: JournalWritePayload,
): JournalValidationErrors {
  const errors: JournalValidationErrors = {};

  if (!DATE_PATTERN.test(payload.journal_date.trim())) {
    errors.journal_date = "Enter the journal date as YYYY-MM-DD.";
  }

  if (payload.description !== null && payload.description.length > 1000) {
    errors.description = "The description may not be longer than 1000 characters.";
  }

  if (payload.reference !== null && payload.reference.length > 255) {
    errors.reference = "The reference may not be longer than 255 characters.";
  }

  if (payload.lines.length < 2) {
    errors.lines = "A journal needs at least two lines.";
  } else if (payload.lines.length > MAX_JOURNAL_LINES) {
    errors.lines = `A journal may not have more than ${MAX_JOURNAL_LINES} lines.`;
  }

  let totalDebit = BigInt(0);
  let totalCredit = BigInt(0);
  let amountsValid = true;

  payload.lines.forEach((line, index) => {
    if (!Number.isInteger(line.account_id) || line.account_id <= 0) {
      errors[`lines.${index}.account_id`] = "Select an account for this line.";
    }

    const debit = parseScaled(line.debit);
    const credit = parseScaled(line.credit);

    if (debit === null) {
      errors[`lines.${index}.debit`] = "Enter a valid debit amount (up to 4 decimals).";
    }

    if (credit === null) {
      errors[`lines.${index}.credit`] = "Enter a valid credit amount (up to 4 decimals).";
    }

    if (debit === null || credit === null) {
      amountsValid = false;
      return;
    }

    if (debit > BigInt(0) && credit > BigInt(0)) {
      errors[`lines.${index}.amount`] =
        "A line holds an amount on one side only — debit or credit, not both.";
    } else if (debit === BigInt(0) && credit === BigInt(0)) {
      errors[`lines.${index}.amount`] = "Enter a debit or credit amount for this line.";
    }

    totalDebit += debit;
    totalCredit += credit;
  });

  if (amountsValid && payload.lines.length >= 2 && totalDebit !== totalCredit) {
    errors.lines = "The journal is not balanced: total debits must equal total credits.";
  }

  return errors;
}
