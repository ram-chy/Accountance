import { describe, expect, it } from "vitest";

import type { Journal, JournalWritePayload } from "./types";
import {
  parseJournal,
  parseJournalLine,
  parseJournalList,
  validateJournalWrite,
} from "./validators";

function lineResource(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 1,
    line_number: 1,
    account_id: 10,
    account_code: "1000",
    account_name: "Cash",
    description: null,
    debit: "100.0000",
    credit: "0.0000",
    currency_id: null,
    currency_code: null,
    exchange_rate: null,
    foreign_debit: null,
    foreign_credit: null,
    ...overrides,
  };
}

function resource(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 5,
    journal_number: "JNL-000005",
    journal_date: "2027-01-15",
    description: "Opening entry",
    reference: "INV-1",
    status: "DRAFT",
    source_type: "MANUAL",
    source_id: null,
    created_by: 1,
    posted_by: null,
    posted_at: null,
    created_at: null,
    updated_at: null,
    lines: [lineResource()],
    totals: { total_debit: "100.0000", total_credit: "100.0000", is_balanced: true },
    ...overrides,
  };
}

function payload(overrides: Partial<JournalWritePayload> = {}): JournalWritePayload {
  return {
    journal_date: "2027-01-15",
    description: null,
    reference: null,
    lines: [
      { account_id: 10, description: null, debit: "100", credit: "0" },
      { account_id: 20, description: null, debit: "0", credit: "100" },
    ],
    ...overrides,
  };
}

describe("parseJournalLine", () => {
  it("narrows a complete line", () => {
    const line = parseJournalLine(lineResource());

    expect(line?.account_id).toBe(10);
    expect(line?.debit).toBe("100.0000");
    expect(line?.currency_code).toBeNull();
  });

  it("rejects a line without an account", () => {
    expect(parseJournalLine({ id: 1, line_number: 1, debit: "1", credit: "0" })).toBeNull();
  });
});

describe("parseJournal", () => {
  it("narrows a journal and its totals", () => {
    const journal = parseJournal(resource());

    expect(journal?.journal_number).toBe("JNL-000005");
    expect(journal?.status).toBe("DRAFT");
    expect(journal?.lines).toHaveLength(1);
    expect(journal?.totals?.is_balanced).toBe(true);
  });

  it("rejects an unknown status", () => {
    expect(parseJournal(resource({ status: "PENDING" }))).toBeNull();
  });

  it("rejects a journal without a number", () => {
    expect(parseJournal(resource({ journal_number: undefined }))).toBeNull();
  });
});

describe("parseJournalList", () => {
  it("narrows a flat array and infers hasMore from a full page", () => {
    const state = parseJournalList([resource(), resource({ id: 6 })], 2);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(2);
    expect(state.hasMore).toBe(true);
  });

  it("skips invalid rows without fabricating", () => {
    const state = parseJournalList([resource(), { id: 9 }], 50);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });

  it("errors when no row is valid", () => {
    const state = parseJournalList([{ id: 9 }], 50);

    expect(state.status).toBe("error");
  });
});

describe("validateJournalWrite", () => {
  it("accepts a balanced two-line entry", () => {
    expect(validateJournalWrite(payload())).toEqual({});
  });

  it("rejects a date that is not Y-m-d", () => {
    expect(validateJournalWrite(payload({ journal_date: "15/01/2027" })).journal_date).toBeDefined();
  });

  it("requires at least two lines", () => {
    expect(
      validateJournalWrite(
        payload({ lines: [{ account_id: 10, description: null, debit: "10", credit: "0" }] }),
      ).lines,
    ).toBeDefined();
  });

  it("reports an unbalanced entry against the whole journal", () => {
    const errors = validateJournalWrite(
      payload({
        lines: [
          { account_id: 10, description: null, debit: "100", credit: "0" },
          { account_id: 20, description: null, debit: "0", credit: "90" },
        ],
      }),
    );

    expect(errors.lines).toBeDefined();
  });

  it("rejects a line that holds both a debit and a credit", () => {
    const errors = validateJournalWrite(
      payload({
        lines: [
          { account_id: 10, description: null, debit: "100", credit: "100" },
          { account_id: 20, description: null, debit: "0", credit: "0" },
        ],
      }),
    );

    expect(errors["lines.0.amount"]).toBeDefined();
  });

  it("rejects a line with neither side", () => {
    const errors = validateJournalWrite(
      payload({
        lines: [
          { account_id: 10, description: null, debit: "0", credit: "0" },
          { account_id: 20, description: null, debit: "20", credit: "0" },
        ],
      }),
    );

    expect(errors["lines.0.amount"]).toBeDefined();
  });

  it("requires an account on every line", () => {
    const errors = validateJournalWrite(
      payload({
        lines: [
          { account_id: 0, description: null, debit: "100", credit: "0" },
          { account_id: 20, description: null, debit: "0", credit: "100" },
        ],
      }),
    );

    expect(errors["lines.0.account_id"]).toBeDefined();
  });

  it("rejects more than four decimal places", () => {
    const errors = validateJournalWrite(
      payload({
        lines: [
          { account_id: 10, description: null, debit: "100.00001", credit: "0" },
          { account_id: 20, description: null, debit: "0", credit: "100.00001" },
        ],
      }),
    );

    expect(errors["lines.0.debit"]).toBeDefined();
  });
});

describe("parseJournal type usage", () => {
  it("keeps the Journal shape stable", () => {
    const journal: Journal | null = parseJournal(resource());
    expect(journal?.id).toBe(5);
  });
});
