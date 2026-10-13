import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ReceivablesAgingView } from "./receivables-aging-view";

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
  useCustomerOptions: () => ({ status: "ready", items: [] }),
}));

vi.mock("@/lib/reports/use-receivables-aging", () => ({
  useReceivablesAging: () => state.report,
}));

function readyReport() {
  return {
    status: "ready",
    data: {
      as_of: "2026-06-30",
      base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
      buckets: [
        { key: "current", label: "Current (0-30)", min: null, max: 30, total: "100.0000" },
        { key: "days_31_60", label: "31-60 days", min: 31, max: 60, total: "600.0000" },
      ],
      rows: [
        {
          counterparty: { id: 4, code: "C-001", name: "Acme" },
          buckets: { current: "100.0000", days_31_60: "600.0000" },
          total: "700.0000",
        },
      ],
      totals: {
        by_bucket: { current: "100.0000", days_31_60: "600.0000" },
        total: "700.0000",
      },
    },
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.reports.view"]);
  state.report = null;
});

describe("ReceivablesAgingView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<ReceivablesAgingView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the report without the reports permission", () => {
    state.perms = new Set<string>();

    render(<ReceivablesAgingView />);

    expect(screen.getByText("Reports unavailable")).toBeInTheDocument();
  });

  it("renders data-driven aging bucket columns and row totals", () => {
    state.report = readyReport();

    render(<ReceivablesAgingView />);

    expect(screen.getByRole("heading", { name: "Aged Receivables" })).toBeInTheDocument();
    expect(screen.getByText("Current (0-30)")).toBeInTheDocument();
    expect(screen.getByText("31-60 days")).toBeInTheDocument();
    expect(screen.getByText("Acme")).toBeInTheDocument();
  });

  it("shows an error state with retry on failure", () => {
    state.report = { status: "error", data: null, message: "Boom", requestId: "req-7" };

    render(<ReceivablesAgingView />);

    expect(screen.getByText("Boom")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });
});