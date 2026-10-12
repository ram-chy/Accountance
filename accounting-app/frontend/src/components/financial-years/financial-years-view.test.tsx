import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { FinancialYear, FinancialYearListState } from "@/lib/financial-years/types";

import { FinancialYearsView } from "./financial-years-view";

const ALL_PERMISSIONS = [
  "accounting.periods.view",
  "accounting.periods.create",
  "accounting.periods.update",
  "accounting.periods.close",
];

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    list: null as unknown,
  },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({
    activeCompany: state.hasCompany ? { id: 1, name: "Acme Ltd" } : null,
  }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({
    can: (name: string) => state.perms.has(name),
  }),
}));

vi.mock("@/lib/financial-years/use-financial-years", () => ({
  FINANCIAL_YEAR_LIST_PER_PAGE: 50,
  useFinancialYearList: () => state.list,
}));

vi.mock("@/lib/financial-years/api", () => ({
  createFinancialYear: vi.fn(),
  updateFinancialYear: vi.fn(),
  generateFinancialYearPeriods: vi.fn(),
  closeFinancialYear: vi.fn(),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

function buildYear(overrides: Partial<FinancialYear> = {}): FinancialYear {
  return {
    id: 1,
    name: "2026-2027",
    start_date: "2026-04-01",
    end_date: "2027-03-31",
    status: "OPEN",
    accepts_postings: true,
    periods_count: 12,
    open_periods_count: 0,
    can_close: true,
    created_by: 1,
    closed_by: null,
    closed_at: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function readyState(items: FinancialYear[]): FinancialYearListState {
  return { status: "ready", items, perPage: 50, hasMore: false };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(ALL_PERMISSIONS);
  state.list = readyState([buildYear()]);
});

describe("FinancialYearsView", () => {
  it("renders financial years from the loaded list", () => {
    state.list = readyState([
      buildYear({ id: 1, name: "2026-2027" }),
      buildYear({ id: 2, name: "2025-2026", status: "CLOSED", can_close: false }),
    ]);

    render(<FinancialYearsView />);

    expect(screen.getByRole("heading", { name: "Financial years" })).toBeInTheDocument();
    expect(screen.getAllByText("2026-2027").length).toBeGreaterThan(0);
    expect(screen.getAllByText("2025-2026").length).toBeGreaterThan(0);
  });

  it("shows the empty state when there are no years", () => {
    state.list = readyState([]);

    render(<FinancialYearsView />);

    expect(screen.getByText("No financial years yet")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<FinancialYearsView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the create button without the create permission", () => {
    state.perms = new Set(["accounting.periods.view"]);

    render(<FinancialYearsView />);

    expect(screen.queryByRole("button", { name: "New financial year" })).not.toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.list = {
      status: "error",
      items: [],
      perPage: 50,
      hasMore: false,
      message: "Server exploded.",
    };

    render(<FinancialYearsView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("opens the create dialog from the header action", async () => {
    const user = userEvent.setup();

    render(<FinancialYearsView />);

    await user.click(screen.getByRole("button", { name: "New financial year" }));

    expect(screen.getByRole("dialog", { name: "New financial year" })).toBeInTheDocument();
  });

  it("opens the generate confirmation for a row", async () => {
    const user = userEvent.setup();

    render(<FinancialYearsView />);

    await user.click(screen.getAllByRole("button", { name: "Generate periods" })[0]);

    expect(
      screen.getByRole("dialog", { name: "Generate accounting periods" }),
    ).toBeInTheDocument();
  });

  it("disables finalize until every period is closed", () => {
    state.list = readyState([buildYear({ can_close: false, open_periods_count: 3 })]);

    render(<FinancialYearsView />);

    const finalize = screen.getAllByRole("button", { name: "Finalize" })[0];
    expect(finalize).toBeDisabled();
    expect(finalize).toHaveAttribute(
      "title",
      "Close all 3 open period(s) before finalizing.",
    );
  });

  it("enables finalize when the backend says the year can close", () => {
    render(<FinancialYearsView />);

    expect(screen.getAllByRole("button", { name: "Finalize" })[0]).toBeEnabled();
  });
});
