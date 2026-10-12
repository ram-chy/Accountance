import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { DashboardData } from "@/lib/dashboard/types";

import { DashboardView } from "./dashboard-view";

const { state } = vi.hoisted(() => ({
  state: {
    data: null as unknown,
    perms: { reports: true, cashBank: true, journals: true },
  },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({
    activeCompany: {
      id: 1,
      name: "Acme Ltd",
      settings: { default_fiscal_year_start_month: 4 },
    },
  }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({
    can: (name: string) => {
      if (name === "accounting.reports.view") return state.perms.reports;
      if (name === "accounting.cash_bank.view") return state.perms.cashBank;
      if (name === "accounting.ledger.view") return state.perms.cashBank;
      if (name === "journals.view") return state.perms.journals;
      return false;
    },
  }),
}));

vi.mock("@/lib/dashboard/use-dashboard-data", () => ({
  useDashboardData: () => state.data,
}));

const currency = { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 };

function buildData(): DashboardData {
  return {
    currency,
    trendLimited: false,
    income: { status: "ready", data: "1000.0000" },
    expenses: { status: "ready", data: "400.0000" },
    netProfit: {
      status: "ready",
      data: { amount: "600.0000", profitable: true },
    },
    receivables: { status: "ready", data: "250.0000" },
    payables: { status: "ready", data: "125.0000" },
    cashBank: { status: "ready", data: { total: "9999.0000", accountCount: 2 } },
    expenseBreakdown: {
      status: "ready",
      data: [{ accountId: 2, code: "5000", name: "Rent", amount: "400.0000" }],
    },
    trend: {
      status: "ready",
      data: [
        {
          key: "2026-04",
          label: "Apr 2026",
          revenue: "1000.0000",
          expenses: "400.0000",
        },
      ],
    },
    activity: {
      status: "ready",
      data: [
        {
          id: 7,
          number: "JV-7",
          date: "2026-05-02",
          description: "Opening balances",
          reference: null,
          status: "POSTED",
          sourceType: "manual_entry",
          amount: "7777.0000",
        },
      ],
    },
  };
}

beforeEach(() => {
  state.data = buildData();
  state.perms = { reports: true, cashBank: true, journals: true };
});

describe("DashboardView", () => {
  it("renders the summary, charts and activity from the loaded data", () => {
    render(<DashboardView />);

    expect(
      screen.getByRole("heading", { name: "Dashboard" }),
    ).toBeInTheDocument();

    expect(screen.getByText("$ 1,000.00")).toBeInTheDocument();
    expect(screen.getByText("$ 600.00")).toBeInTheDocument();
    expect(screen.getByText("$ 250.00")).toBeInTheDocument();
    expect(screen.getByText("$ 125.00")).toBeInTheDocument();
    expect(screen.getByText("$ 9,999.00")).toBeInTheDocument();
    expect(screen.getByText("Profit")).toBeInTheDocument();

    expect(screen.getByText("Income versus expenses")).toBeInTheDocument();
    expect(screen.getByText("Expense breakdown")).toBeInTheDocument();

    expect(screen.getByText("Recent activity")).toBeInTheDocument();
    expect(screen.getByText("JV-7")).toBeInTheDocument();
  });

  it("hides report metrics the role cannot read", () => {
    state.perms = { reports: false, cashBank: false, journals: true };

    render(<DashboardView />);

    expect(screen.getByText("No accounting overview")).toBeInTheDocument();
    expect(
      screen.queryByText("Income versus expenses"),
    ).not.toBeInTheDocument();
    expect(screen.queryByText("$ 1,000.00")).not.toBeInTheDocument();
  });
});
