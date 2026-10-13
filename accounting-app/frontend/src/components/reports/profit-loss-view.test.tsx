import { fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProfitLossView } from "./profit-loss-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>(),
    pl: null as unknown,
  },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({ activeCompany: state.hasCompany ? { id: 1, name: "Acme Ltd" } : null }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({ can: (name: string) => state.perms.has(name) }),
}));

vi.mock("@/lib/reports/use-profit-loss", () => ({
  useProfitLoss: () => state.pl,
}));

function readyProfitLoss() {
  return {
    status: "ready",
    data: {
      period: { from: "2026-01-01", to: "2026-12-31" },
      base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
      revenue: {
        total: "1000.0000",
        accounts: [{ account_id: 1, code: "4000", name: "Sales", amount: "1000.0000" }],
      },
      expenses: {
        total: "600.0000",
        accounts: [{ account_id: 2, code: "5000", name: "Rent", amount: "600.0000" }],
      },
      gross_profit: "400.0000",
      net_profit: "350.0000",
      is_profitable: true,
    },
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.reports.view"]);
  state.pl = { status: "loading", data: null };
});

describe("ProfitLossView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<ProfitLossView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the report without the reports permission", () => {
    state.perms = new Set<string>();

    render(<ProfitLossView />);

    expect(screen.getByText("Reports unavailable")).toBeInTheDocument();
  });

  it("renders the revenue and expense sections and the backend profit figures", () => {
    state.pl = readyProfitLoss();

    render(<ProfitLossView />);

    expect(screen.getByRole("heading", { name: "Revenue" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Expenses" })).toBeInTheDocument();
    expect(screen.getByText("400.00")).toBeInTheDocument();
    expect(screen.getByText("350.00")).toBeInTheDocument();
    expect(screen.getByText("Profitable: Yes")).toBeInTheDocument();
  });

  it("drills down to the general ledger for each account", () => {
    state.pl = readyProfitLoss();

    render(<ProfitLossView />);

    expect(screen.getByRole("link", { name: "4000 — Sales" })).toHaveAttribute(
      "href",
      "/reports/general-ledger?account_id=1",
    );
    expect(screen.getByRole("link", { name: "5000 — Rent" })).toHaveAttribute(
      "href",
      "/reports/general-ledger?account_id=2",
    );
  });

  it("preserves the applied date range in the drill-down links", async () => {
    state.pl = readyProfitLoss();

    render(<ProfitLossView />);

    fireEvent.change(screen.getByLabelText("From"), { target: { value: "2026-01-01" } });
    fireEvent.change(screen.getByLabelText("To"), { target: { value: "2026-06-30" } });
    await userEvent.click(screen.getByRole("button", { name: "Apply" }));

    expect(screen.getByRole("link", { name: "4000 — Sales" })).toHaveAttribute(
      "href",
      "/reports/general-ledger?account_id=1&from=2026-01-01&to=2026-06-30",
    );
  });

  it("shows an error state with a retry affordance", () => {
    state.pl = { status: "error", data: null, message: "Boom", requestId: "req-3" };

    render(<ProfitLossView />);

    expect(screen.getByText("Boom")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });
});
