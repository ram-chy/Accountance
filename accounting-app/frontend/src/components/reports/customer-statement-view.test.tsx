import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CustomerStatementView } from "./customer-statement-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>(),
    report: null as unknown,
  },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({ activeCompany: state.hasCompany ? { id: 1, name: "Acme Ltd" } : null }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({ can: (name: string) => state.perms.has(name) }),
}));

vi.mock("@/lib/customers/use-customer-options", () => ({
  useCustomerOptions: () => ({ status: "ready", items: [{ id: 4, customer_code: "C-001", name: "Acme" }] }),
}));

vi.mock("@/lib/reports/use-customer-statement", () => ({
  useCustomerStatement: () => state.report,
}));

function readyStatement() {
  return {
    status: "ready",
    data: {
      customer: { id: 4, code: "C-001", name: "Acme" },
      period: { from: "2026-01-01", to: "2026-06-30" },
      base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
      opening_balance: "0.0000",
      rows: [
        {
          date: "2026-02-01",
          type: "invoice",
          reference: "INV-001",
          description: null,
          debit: "1000.0000",
          credit: "0.0000",
          running_balance: "1000.0000",
          currency_code: null,
          exchange_rate: null,
          foreign_amount: null,
        },
        {
          date: "2026-02-10",
          type: "receipt",
          reference: "RCP-001",
          description: "Bank transfer",
          debit: "0.0000",
          credit: "400.0000",
          running_balance: "600.0000",
          currency_code: null,
          exchange_rate: null,
          foreign_amount: null,
        },
      ],
      totals: { debit: "1000.0000", credit: "400.0000" },
      closing_balance: "600.0000",
    },
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.reports.view"]);
  state.report = null;
});

describe("CustomerStatementView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<CustomerStatementView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the report without the reports permission", () => {
    state.perms = new Set<string>();

    render(<CustomerStatementView />);

    expect(screen.getByText("Reports unavailable")).toBeInTheDocument();
  });

  it("prompts for a customer while the statement is idle", () => {
    state.report = { status: "idle", data: null, message: "" };

    render(<CustomerStatementView />);

    expect(screen.getByText("Select a customer")).toBeInTheDocument();
  });

  it("renders opening balance, movement rows and the closing balance", () => {
    state.report = readyStatement();

    render(<CustomerStatementView />);

    expect(screen.getByRole("heading", { name: "Customer Statement" })).toBeInTheDocument();
    expect(screen.getByText("Opening balance")).toBeInTheDocument();
    expect(screen.getByText("INV-001")).toBeInTheDocument();
    expect(screen.getByText("RCP-001")).toBeInTheDocument();
    expect(screen.getByText("Closing balance")).toBeInTheDocument();
  });

  it("shows an error state with retry on failure", () => {
    state.report = { status: "error", data: null, message: "Boom", requestId: "req-7" };

    render(<CustomerStatementView />);

    expect(screen.getByText("Boom")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });
});