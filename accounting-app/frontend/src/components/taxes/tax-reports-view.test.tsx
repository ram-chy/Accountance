import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { TaxReportsView } from "./tax-reports-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    report: null as unknown,
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

vi.mock("@/lib/taxes/use-tax-report", () => ({
  useTaxReport: () => state.report,
}));

const totals = {
  sales_taxable: "1000.0000",
  output_tax: "200.0000",
  purchase_taxable: "0.0000",
  input_tax: "0.0000",
  net_tax: "200.0000",
  unattributed_tax: "0.0000",
};

function readyReport() {
  return {
    status: "ready",
    summary: { period: { from: null, to: null }, rows: [], totals },
    byTax: {
      period: { from: null, to: null },
      base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
      rows: [
        {
          tax_id: 1,
          tax_code: "VAT",
          tax_name: "VAT standard",
          tax_type: "OUTPUT",
          ...totals,
        },
      ],
      totals,
    },
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.tax.report.view"]);
  state.report = readyReport();
});

describe("TaxReportsView", () => {
  it("renders the summary figures and by-tax rows", () => {
    render(<TaxReportsView />);

    expect(screen.getByRole("heading", { name: "Tax reports" })).toBeInTheDocument();
    expect(screen.getByText("VAT · VAT standard")).toBeInTheDocument();
    expect(screen.getByText("Amounts are in USD.")).toBeInTheDocument();
  });

  it("labels a deleted tax rather than dropping its row", () => {
    state.report = {
      ...readyReport(),
      byTax: {
        period: { from: null, to: null },
        base_currency: undefined,
        rows: [
          {
            tax_id: 7,
            tax_code: null,
            tax_name: null,
            tax_type: null,
            ...totals,
          },
        ],
        totals,
      },
    };

    render(<TaxReportsView />);

    expect(screen.getByText("Tax #7")).toBeInTheDocument();
  });

  it("hides the reports without the report permission", () => {
    state.perms = new Set<string>();

    render(<TaxReportsView />);

    expect(screen.getByText("Tax reports unavailable")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<TaxReportsView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.report = {
      status: "error",
      summary: null,
      byTax: null,
      message: "Server exploded.",
    };

    render(<TaxReportsView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });
});
