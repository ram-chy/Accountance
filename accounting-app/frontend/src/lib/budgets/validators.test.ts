import { describe, expect, it } from "vitest";

import type { Budget, BudgetLine, BudgetVarianceReport } from "./types";
import {
  isPositiveBudgetAmount,
  parseBudget,
  parseBudgetDetail,
  parseBudgetLine,
  parseBudgetList,
  parseBudgetOrThrow,
  parseBudgetVariance,
  parseBudgetVarianceState,
  validateBudgetCreate,
  validateBudgetLineCreate,
  validateBudgetLineUpdate,
  validateBudgetRevise,
  validateBudgetUpdate,
} from "./validators";

const validBudget: Budget = {
  id: 5,
  company_id: 1,
  financial_year_id: 4,
  code: "BUD-2026",
  name: "Ops plan",
  version_number: 1,
  parent_budget_id: null,
  status: "DRAFT",
  notes: null,
  created_by: 1,
  updated_by: 1,
  approved_by: null,
  approved_at: null,
  financial_year: {
    id: 4,
    name: "FY2026",
    start_date: "2026-01-01",
    end_date: "2026-12-31",
    status: "OPEN",
  },
  lines_count: 1,
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
};

const validLine: BudgetLine = {
  id: 11,
  budget_id: 5,
  account_id: 1,
  accounting_period_id: 2,
  amount: "1000.0000",
  description: null,
  account: { id: 1, code: "4000", name: "Sales", account_type: "REVENUE", is_active: true },
  accounting_period: {
    id: 2,
    name: "Jan 2026",
    start_date: "2026-01-01",
    end_date: "2026-01-31",
    status: "OPEN",
    financial_year_id: 4,
  },
  dimensions: [{ dimension_id: 3, value_id: 9 }],
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
};

describe("parseBudget", () => {
  it("narrows a complete budget row with its lines", () => {
    const parsed = parseBudget({ ...validBudget, lines: [validLine] });

    expect(parsed).not.toBeNull();
    expect(parsed?.status).toBe("DRAFT");
    expect(parsed?.lines).toHaveLength(1);
    expect(parsed?.lines?.[0].dimensions?.[0]).toEqual({ dimension_id: 3, value_id: 9 });
  });

  it("rejects an unknown status", () => {
    expect(parseBudget({ ...validBudget, status: "ARCHIVED" })).toBeNull();
  });

  it("rejects a row missing a required identifier", () => {
    expect(parseBudget({ ...validBudget, financial_year_id: undefined })).toBeNull();
  });
});

describe("parseBudgetLine", () => {
  it("narrows a complete line", () => {
    const parsed = parseBudgetLine(validLine);

    expect(parsed).not.toBeNull();
    expect(parsed?.amount).toBe("1000.0000");
    expect(parsed?.account?.code).toBe("4000");
  });

  it("rejects an amount that is not a decimal string", () => {
    expect(parseBudgetLine({ ...validLine, amount: "not money" })).toBeNull();
    expect(parseBudgetLine({ ...validLine, amount: 1000 })).toBeNull();
  });
});

describe("parseBudgetOrThrow", () => {
  it("throws on an invalid payload", () => {
    expect(() => parseBudgetOrThrow({ id: 1 })).toThrow();
  });
});

describe("budget lists", () => {
  it("parses an envelope-wrapped list", () => {
    const state = parseBudgetList({ success: true, data: [validBudget] }, 25);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });

  it("errors when no budget row survives parsing", () => {
    expect(parseBudgetList({ data: [{ id: 1 }] }, 25).status).toBe("error");
  });

  it("errors when the payload is not a list", () => {
    expect(parseBudgetList("nope", 25).status).toBe("error");
  });

  it("parses a single budget detail", () => {
    const state = parseBudgetDetail(validBudget);

    expect(state.status).toBe("ready");
    expect(state.budget?.code).toBe("BUD-2026");
  });
});

function readyVariance(): BudgetVarianceReport {
  return {
    budget: {
      id: 5,
      code: "BUD-2026",
      name: "Ops plan",
      version_number: 1,
      status: "APPROVED",
      financial_year: {
        id: 4,
        name: "FY2026",
        start_date: "2026-01-01",
        end_date: "2026-12-31",
      },
    },
    period: { from: "2026-01-01", to: "2026-12-31" },
    base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
    summary: {
      revenue: { budget: "1000.0000", actual: "1200.0000", variance: "200.0000" },
      expenses: { budget: "800.0000", actual: "700.0000", variance: "-100.0000" },
      net: { budget: "200.0000", actual: "500.0000", variance: "300.0000", status: "FAVOURABLE" },
      counts: { favourable: 2, unfavourable: 1, on_target: 0 },
    },
    lines: [
      {
        account_id: 1,
        account_code: "4000",
        account_name: "Sales",
        account_type: "REVENUE",
        accounting_period_id: 2,
        period_name: "Jan 2026",
        period_start: "2026-01-01",
        period_end: "2026-01-31",
        budget: "1000.0000",
        actual: "1200.0000",
        variance: "200.0000",
        variance_percentage: "20.0000",
        status: "FAVOURABLE",
      },
    ],
  };
}

describe("parseBudgetVariance", () => {
  it("narrows the summary, counts and lines", () => {
    const report = parseBudgetVariance(readyVariance());

    expect(report).not.toBeNull();
    expect(report?.summary.net.status).toBe("FAVOURABLE");
    expect(report?.summary.counts.favourable).toBe(2);
    expect(report?.lines[0].variance_percentage).toBe("20.0000");
  });

  it("keeps a null variance percentage rather than fabricating a number", () => {
    const raw = readyVariance();
    raw.lines[0].variance_percentage = null;

    const report = parseBudgetVariance(raw);

    expect(report?.lines[0].variance_percentage).toBeNull();
  });

  it("wraps the report in a ready state", () => {
    expect(parseBudgetVarianceState(readyVariance()).status).toBe("ready");
  });

  it("reports an error for an invalid payload", () => {
    const state = parseBudgetVarianceState({ summary: {} });

    expect(state.status).toBe("error");
    expect(state.data).toBeNull();
  });
});

describe("budget write validation", () => {
  it("accepts a complete create payload", () => {
    expect(validateBudgetCreate({ financial_year_id: 4, code: "BUD", name: "Plan", notes: null })).toEqual({});
  });

  it("flags the required create fields", () => {
    const errors = validateBudgetCreate({ financial_year_id: 0, code: " ", name: "", notes: null });

    expect(errors.financial_year_id).toBeDefined();
    expect(errors.code).toBeDefined();
    expect(errors.name).toBeDefined();
  });

  it("only validates supplied update fields", () => {
    expect(validateBudgetUpdate({})).toEqual({});
    expect(validateBudgetUpdate({ name: "" })).toHaveProperty("name");
  });

  it("only validates supplied revise fields", () => {
    expect(validateBudgetRevise({})).toEqual({});
    expect(validateBudgetRevise({ name: "" })).toHaveProperty("name");
  });

  it("accepts a non-negative line amount as a decimal string", () => {
    expect(
      validateBudgetLineCreate({
        account_id: 1,
        accounting_period_id: 2,
        amount: "1000.0000",
        description: null,
        dimensions: [],
      }),
    ).toEqual({});
  });

  it("rejects a negative or non-numeric line amount without using Number", () => {
    const base = {
      account_id: 1,
      accounting_period_id: 2,
      description: null,
      dimensions: [],
    };

    expect(validateBudgetLineCreate({ ...base, amount: "-1.00" })).toHaveProperty("amount");
    expect(validateBudgetLineCreate({ ...base, amount: "1e3" })).toHaveProperty("amount");
    expect(validateBudgetLineCreate({ ...base, amount: "" })).toHaveProperty("amount");
  });

  it("flags a missing account or period on a line create", () => {
    const errors = validateBudgetLineCreate({
      account_id: 0,
      accounting_period_id: 0,
      amount: "1.0000",
      description: null,
      dimensions: [],
    });

    expect(errors.account_id).toBeDefined();
    expect(errors.accounting_period_id).toBeDefined();
  });

  it("only validates supplied line update fields", () => {
    expect(validateBudgetLineUpdate({})).toEqual({});
    expect(validateBudgetLineUpdate({ amount: "-1" })).toHaveProperty("amount");
  });

  it("recognises a strictly positive amount for a pre-submit hint", () => {
    expect(isPositiveBudgetAmount("0.0000")).toBe(false);
    expect(isPositiveBudgetAmount("0.0100")).toBe(true);
    expect(isPositiveBudgetAmount("1000")).toBe(true);
  });
});
