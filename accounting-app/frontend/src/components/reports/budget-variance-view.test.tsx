import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { BudgetVarianceView } from "./budget-variance-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>(),
    budgets: { status: "ready", items: [] as Array<unknown> },
    dimensions: { status: "ready", items: [] as Array<unknown> },
    values: { status: "ready", items: [] as Array<unknown> },
    variance: { status: "loading", data: null } as unknown,
    search: {} as Record<string, string>,
    lastCall: null as null | { budgetId: number | null; filters: { dimension_id: number | null; dimension_value_id: number | null } },
  },
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () => ({ get: (key: string) => state.search[key] ?? null }),
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({ activeCompany: state.hasCompany ? { id: 1, name: "Acme Ltd" } : null }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({ can: (name: string) => state.perms.has(name) }),
}));

vi.mock("@/lib/budgets/use-budget-options", () => ({
  useBudgetOptions: () => state.budgets,
}));

vi.mock("@/lib/dimensions/use-dimension-options", () => ({
  useFinancialDimensionOptions: () => state.dimensions,
}));

vi.mock("@/lib/dimensions/use-dimension-value-options", () => ({
  useFinancialDimensionValueOptions: () => state.values,
}));

vi.mock("@/lib/budgets/use-budget-variance", () => ({
  useBudgetVariance: (options: {
    budgetId: number | null;
    filters: { dimension_id: number | null; dimension_value_id: number | null };
  }) => {
    state.lastCall = { budgetId: options.budgetId, filters: options.filters };
    return state.variance;
  },
}));

function budgetOption(id: number, code: string, name: string) {
  return { id, code, name, version_number: 1, status: "DRAFT" as const };
}

function dimensionOption(id: number, code: string, name: string, isActive = true) {
  return { id, type: "COST_CENTER" as const, type_label: "Cost Center", code, name, is_active: isActive };
}

function valueOption(id: number, code: string, name: string, isActive = true) {
  return { id, code, name, is_active: isActive };
}

function readyVariance() {
  return {
    status: "ready",
    data: {
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
          variance_percentage: "20.0000" as string | null,
          status: "FAVOURABLE",
        },
      ],
    },
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.budgets.view"]);
  state.budgets = { status: "ready", items: [] };
  state.dimensions = { status: "ready", items: [] };
  state.values = { status: "ready", items: [] };
  state.variance = { status: "loading", data: null };
  state.search = {};
  state.lastCall = null;
});

describe("BudgetVarianceView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<BudgetVarianceView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the report without the budget permission", () => {
    state.perms = new Set<string>();

    render(<BudgetVarianceView />);

    expect(screen.getByText("Budgets unavailable")).toBeInTheDocument();
  });

  it("asks for a budget when none is selected", () => {
    state.budgets = { status: "ready", items: [budgetOption(5, "BUD-2026", "Ops plan")] };

    render(<BudgetVarianceView />);

    expect(screen.getByText("Select a budget")).toBeInTheDocument();
  });

  it("seeds the budget and dimension filters from the URL", () => {
    state.search = { budget_id: "5", dimension_id: "2", dimension_value_id: "7" };

    render(<BudgetVarianceView />);

    expect(state.lastCall).toEqual({
      budgetId: 5,
      filters: { dimension_id: 2, dimension_value_id: 7 },
    });
  });

  it("renders the summary and the per-line variance from the backend", () => {
    state.search = { budget_id: "5" };
    state.variance = readyVariance();

    render(<BudgetVarianceView />);

    expect(screen.getByText("BUD-2026")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Revenue" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Expenses" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Net" })).toBeInTheDocument();
    // "1,000.00" is both the revenue plan and that line's budget, so it repeats.
    expect(screen.getAllByText("1,000.00").length).toBeGreaterThan(0);
    expect(screen.getAllByText("1,200.00").length).toBeGreaterThan(0);
    expect(screen.getByText("20.00%")).toBeInTheDocument();
    expect(screen.getByText("4000")).toBeInTheDocument();
    expect(screen.getByText("Sales")).toBeInTheDocument();
    expect(screen.getAllByText("Favourable").length).toBeGreaterThan(0);
  });

  it("renders n/a for a line whose plan was zero", () => {
    state.search = { budget_id: "5" };
    const report = readyVariance();
    report.data.lines[0].variance_percentage = null;
    state.variance = report;

    render(<BudgetVarianceView />);

    expect(screen.getByText("n/a")).toBeInTheDocument();
  });

  it("applies the budget, dimension and value chosen in the pickers", async () => {
    state.budgets = {
      status: "ready",
      items: [budgetOption(7, "BUD-A", "Plan A"), budgetOption(8, "BUD-B", "Plan B")],
    };
    state.dimensions = {
      status: "ready",
      items: [dimensionOption(2, "CC", "Cost center"), dimensionOption(3, "PRJ", "Project", false)],
    };
    state.values = {
      status: "ready",
      items: [valueOption(20, "CC-001", "Sales"), valueOption(21, "CC-002", "Retired", false)],
    };

    render(<BudgetVarianceView />);

    // Only the active dimension is offered.
    const dimensionSelect = screen.getByLabelText("Dimension");
    expect(within(dimensionSelect).getAllByRole("option")).toHaveLength(2);

    await userEvent.selectOptions(screen.getByLabelText("Budget"), "7");
    await userEvent.selectOptions(dimensionSelect, "2");

    // Only the active value is offered.
    const valueSelect = screen.getByLabelText("Dimension value");
    expect(within(valueSelect).getAllByRole("option")).toHaveLength(2);

    await userEvent.selectOptions(valueSelect, "20");
    await userEvent.click(screen.getByRole("button", { name: "Apply" }));

    expect(state.lastCall).toEqual({
      budgetId: 7,
      filters: { dimension_id: 2, dimension_value_id: 20 },
    });
  });

  it("clears the chosen filters", async () => {
    state.search = { budget_id: "5", dimension_id: "2" };
    state.variance = readyVariance();

    render(<BudgetVarianceView />);

    await userEvent.click(screen.getByRole("button", { name: "Clear" }));

    expect(state.lastCall).toEqual({
      budgetId: null,
      filters: { dimension_id: null, dimension_value_id: null },
    });
  });

  it("offers to pick another budget when the selected one is gone", () => {
    state.search = { budget_id: "5" };
    state.variance = { status: "notfound", data: null };

    render(<BudgetVarianceView />);

    expect(screen.getByText("Budget not found")).toBeInTheDocument();
  });

  it("surfaces a load error with a retry affordance", () => {
    state.search = { budget_id: "5" };
    state.variance = { status: "error", data: null, message: "Boom", requestId: "req-1" };

    render(<BudgetVarianceView />);

    expect(screen.getByText("Boom")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("shows the backend's reason when the report is unavailable", () => {
    state.search = { budget_id: "5" };
    state.variance = { status: "unavailable", data: null, message: "No access to this budget." };

    render(<BudgetVarianceView />);

    expect(screen.getByText("Budgets unavailable")).toBeInTheDocument();
    expect(screen.getByText("No access to this budget.")).toBeInTheDocument();
  });
});
