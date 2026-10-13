import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { SupplierStatementView } from "./supplier-statement-view";

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

vi.mock("@/lib/suppliers/use-supplier-options", () => ({
  useSupplierOptions: () => ({ status: "ready", items: [{ id: 3, supplier_code: "S-001", name: "Parts Ltd" }] }),
}));

vi.mock("@/lib/reports/use-supplier-statement", () => ({
  useSupplierStatement: () => state.report,
}));

function readyStatement() {
  return {
    status: "ready",
    data: {
      supplier: { id: 3, code: "S-001", name: "Parts Ltd" },
      period: { from: "2026-01-01", to: "2026-06-30" },
      base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
      opening_balance: "0.0000",
      rows: [
        {
          date: "2026-02-01",
          type: "bill",
          reference: "BILL-001",
          description: null,
          debit: "0.0000",
          credit: "1000.0000",
          running_balance: "1000.0000",
          currency_code: null,
          exchange_rate: null,
          foreign_amount: null,
        },
        {
          date: "2026-02-10",
          type: "payment",
          reference: "PAY-001",
          description: "Bank transfer",
          debit: "400.0000",
          credit: "0.0000",
          running_balance: "600.0000",
          currency_code: null,
          exchange_rate: null,
          foreign_amount: null,
        },
      ],
      totals: { debit: "400.0000", credit: "1000.0000" },
      closing_balance: "600.0000",
    },
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.reports.view"]);
  state.report = null;
});

describe("SupplierStatementView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<SupplierStatementView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the report without the reports permission", () => {
    state.perms = new Set<string>();

    render(<SupplierStatementView />);

    expect(screen.getByText("Reports unavailable")).toBeInTheDocument();
  });

  it("prompts for a supplier while the statement is idle", () => {
    state.report = { status: "idle", data: null, message: "" };

    render(<SupplierStatementView />);

    expect(screen.getByText("Select a supplier")).toBeInTheDocument();
  });

  it("renders opening balance, movement rows and the closing balance", () => {
    state.report = readyStatement();

    render(<SupplierStatementView />);

    expect(screen.getByRole("heading", { name: "Supplier Statement" })).toBeInTheDocument();
    expect(screen.getByText("Opening balance")).toBeInTheDocument();
    expect(screen.getByText("BILL-001")).toBeInTheDocument();
    expect(screen.getByText("PAY-001")).toBeInTheDocument();
    expect(screen.getByText("Closing balance")).toBeInTheDocument();
  });

  it("shows an error state with retry on failure", () => {
    state.report = { status: "error", data: null, message: "Boom", requestId: "req-7" };

    render(<SupplierStatementView />);

    expect(screen.getByText("Boom")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });
});
