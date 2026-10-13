import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { BalanceSheetView } from "./balance-sheet-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>(),
    sheet: null as unknown,
  },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({ activeCompany: state.hasCompany ? { id: 1, name: "Acme Ltd" } : null }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({ can: (name: string) => state.perms.has(name) }),
}));

vi.mock("@/lib/reports/use-balance-sheet", () => ({
  useBalanceSheet: () => state.sheet,
}));

function readySheet() {
  return {
    status: "ready",
    data: {
      period: { from: null, to: "2026-12-31" },
      base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
      assets: {
        total: "1000.0000",
        accounts: [{ account_id: 1, code: "1000", name: "Cash", amount: "1000.0000" }],
      },
      liabilities: { total: "0.0000", accounts: [] },
      equity: {
        total: "1000.0000",
        accounts: [{ account_id: 2, code: "3000", name: "Capital", amount: "1000.0000" }],
        retained_earnings: "0.0000",
      },
      totals: {
        assets: "1000.0000",
        liabilities: "0.0000",
        equity: "1000.0000",
        liabilities_and_equity: "1000.0000",
        is_balanced: true,
        difference: "0.0000",
      },
      current_period_result: "0.0000",
    },
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.reports.view"]);
  state.sheet = null;
});

describe("BalanceSheetView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<BalanceSheetView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the report without the reports permission", () => {
    state.perms = new Set<string>();

    render(<BalanceSheetView />);

    expect(screen.getByText("Reports unavailable")).toBeInTheDocument();
  });

  it("renders the three sections, retained earnings and the balancing totals", () => {
    state.sheet = readySheet();

    render(<BalanceSheetView />);

    expect(screen.getByRole("heading", { name: "Assets" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Liabilities" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Equity" })).toBeInTheDocument();
    expect(screen.getByText("Retained earnings")).toBeInTheDocument();
    expect(screen.getByText("Balanced: Yes")).toBeInTheDocument();
    expect(screen.getByText(/Current period result/)).toBeInTheDocument();
  });

  it("shows an error state with retry on failure", () => {
    state.sheet = { status: "error", data: null, message: "Boom", requestId: "req-9" };

    render(<BalanceSheetView />);

    expect(screen.getByText("Boom")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("shows the unavailable message from the hook", () => {
    state.sheet = { status: "unavailable", data: null, message: "No access" };

    render(<BalanceSheetView />);

    expect(screen.getByText("No access")).toBeInTheDocument();
  });
});
