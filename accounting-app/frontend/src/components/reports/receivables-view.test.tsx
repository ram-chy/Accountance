import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ReceivablesView } from "./receivables-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>(),
    report: null as unknown,
    customers: [] as unknown[],
  },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({ activeCompany: state.hasCompany ? { id: 1, name: "Acme Ltd" } : null }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({ can: (name: string) => state.perms.has(name) }),
}));

vi.mock("@/lib/customers/use-customer-options", () => ({
  useCustomerOptions: () => ({ status: "ready", items: state.customers }),
}));

vi.mock("@/lib/reports/use-receivables", () => ({
  useReceivables: () => state.report,
}));

function readyReport() {
  return {
    status: "ready",
    data: {
      as_of: "2026-06-30",
      base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
      rows: [
        {
          invoice_id: 11,
          invoice_number: "INV-011",
          invoice_date: "2026-05-01",
          due_date: "2026-05-31",
          customer: { id: 4, code: "C-001", name: "Acme" },
          grand_total: "1000.0000",
          paid_total: "400.0000",
          balance_due: "600.0000",
          days_past_due: 30,
          currency_code: "EUR",
          exchange_rate: null,
          base_grand_total: null,
        },
      ],
      count: 1,
      totals: { grand_total: "1000.0000", paid_total: "400.0000", balance_due: "600.0000" },
    },
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.reports.view"]);
  state.report = null;
  state.customers = [{ id: 4, customer_code: "C-001", name: "Acme" }];
});

describe("ReceivablesView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<ReceivablesView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the report without the reports permission", () => {
    state.perms = new Set<string>();

    render(<ReceivablesView />);

    expect(screen.getByText("Reports unavailable")).toBeInTheDocument();
  });

  it("renders outstanding rows, drill-down links and totals", () => {
    state.report = readyReport();

    render(<ReceivablesView />);

    expect(screen.getByRole("heading", { name: "Receivables" })).toBeInTheDocument();
    expect(screen.getByText("INV-011")).toBeInTheDocument();
    expect(screen.getByText("Acme")).toBeInTheDocument();
    expect(screen.getByText(/1 invoice/)).toBeInTheDocument();
    expect(screen.getByText("30d")).toBeInTheDocument();
  });

  it("shows an error state with retry on failure", () => {
    state.report = { status: "error", data: null, message: "Boom", requestId: "req-7" };

    render(<ReceivablesView />);

    expect(screen.getByText("Boom")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("shows the empty state when nothing is outstanding", () => {
    state.report = {
      status: "ready",
      data: {
        as_of: "2026-06-30",
        base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
        rows: [],
        count: 0,
        totals: { grand_total: "0.0000", paid_total: "0.0000", balance_due: "0.0000" },
      },
    };

    render(<ReceivablesView />);

    expect(screen.getByText("Nothing outstanding")).toBeInTheDocument();
  });
});