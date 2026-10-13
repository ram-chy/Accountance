import { fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { TrialBalanceView } from "./trial-balance-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>(),
    tb: null as unknown,
  },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({ activeCompany: state.hasCompany ? { id: 1, name: "Acme Ltd" } : null }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({ can: (name: string) => state.perms.has(name) }),
}));

vi.mock("@/lib/reports/use-trial-balance", () => ({
  useTrialBalance: () => state.tb,
}));

function readyTrialBalance() {
  return {
    status: "ready",
    data: {
      period: { from: "2026-01-01", to: "2026-12-31" },
      base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
      rows: [
        {
          account_id: 1,
          code: "1000",
          name: "Cash",
          account_type: "ASSET",
          debit_total: "1250.2500",
          credit_total: "0.0000",
          net_balance: "1250.2500",
        },
      ],
      totals: {
        debit_total: "1250.2500",
        credit_total: "0.0000",
        net_balance: "1250.2500",
        is_balanced: false,
        difference: "1250.2500",
      },
      include_zero_balances: false,
    },
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.reports.view"]);
  state.tb = { status: "loading", data: null };
});

describe("TrialBalanceView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<TrialBalanceView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the report without the reports permission", () => {
    state.perms = new Set<string>();

    render(<TrialBalanceView />);

    expect(screen.getByText("Reports unavailable")).toBeInTheDocument();
  });

  it("renders backend rows, totals and the balanced indicator", () => {
    state.tb = readyTrialBalance();

    render(<TrialBalanceView />);

    expect(screen.getByRole("link", { name: "1000 — Cash" })).toBeInTheDocument();
    expect(screen.getAllByText("1,250.25").length).toBeGreaterThan(0);
    expect(screen.getByText(/Balanced: No/)).toBeInTheDocument();
  });

  it("drills down to the general ledger carrying the account", () => {
    state.tb = readyTrialBalance();

    render(<TrialBalanceView />);

    expect(screen.getByRole("link", { name: "1000 — Cash" })).toHaveAttribute(
      "href",
      "/reports/general-ledger?account_id=1",
    );
  });

  it("preserves the applied date range in the drill-down link", async () => {
    state.tb = readyTrialBalance();

    render(<TrialBalanceView />);

    fireEvent.change(screen.getByLabelText("From"), { target: { value: "2026-01-01" } });
    fireEvent.change(screen.getByLabelText("To"), { target: { value: "2026-06-30" } });
    await userEvent.click(screen.getByRole("button", { name: "Apply" }));

    expect(screen.getByRole("link", { name: "1000 — Cash" })).toHaveAttribute(
      "href",
      "/reports/general-ledger?account_id=1&from=2026-01-01&to=2026-06-30",
    );
  });

  it("shows an empty state when the backend returns no rows", () => {
    state.tb = {
      status: "ready",
      data: { ...readyTrialBalance().data, rows: [] },
    };

    render(<TrialBalanceView />);

    expect(screen.getByText("No data")).toBeInTheDocument();
  });
});
