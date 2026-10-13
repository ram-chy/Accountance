import { render, screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CashBankReportView } from "./cash-bank-report-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>(),
    report: null as unknown,
    cashBank: { status: "ready", items: [] as Array<{ id: number; code: string; name: string; is_active: boolean }> } as {
      status: string;
      items: Array<{ id: number; code: string; name: string; is_active: boolean }>;
      message?: string;
    },
    allAccounts: { status: "ready", items: [] as Array<{ id: number; code: string; name: string; is_active: boolean }> } as {
      status: string;
      items: Array<{ id: number; code: string; name: string; is_active: boolean }>;
      message?: string;
    },
    search: {} as Record<string, string>,
    lastFilters: null as unknown,
  },
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () => ({ get: (key: string) => state.search[key] ?? null }),
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({ activeCompany: state.hasCompany ? { id: 1, name: "Acme Ltd" } : null }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({ can: (name: string) => state.perms.has(name) }),
}));

vi.mock("@/lib/cash-bank/use-cash-bank-accounts", () => ({
  useCashBankAccountOptions: () => state.cashBank,
}));

vi.mock("@/lib/accounts/use-account-options", () => ({
  useAccountOptions: () => state.allAccounts,
}));

vi.mock("@/lib/reports/use-cash-bank-report", () => ({
  useCashBankReport: (options: { filters: unknown }) => {
    state.lastFilters = options.filters;
    return state.report;
  },
}));

function option(id: number, code: string, name: string) {
  return { id, code, name, is_active: true };
}

function readyReport() {
  return {
    status: "ready",
    data: {
      account: {
        id: 9,
        code: "1010",
        name: "Bank",
        account_type: "ASSET",
        normal_balance: "DEBIT",
        is_active: true,
      },
      period: { from: null, to: null },
      base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
      opening_balance: "200.0000",
      rows: [
        {
          journal_id: 5,
          journal_line_id: 50,
          journal_number: "JNL-000005",
          journal_date: "2026-02-10",
          reference: null,
          description: "Deposit",
          source_type: "CUSTOMER_RECEIPT",
          source_id: 3,
          debit: "100.0000",
          credit: "0.0000",
          running_balance: "300.0000",
          currency_id: null,
          exchange_rate: null,
          foreign_debit: null,
          foreign_credit: null,
        },
      ],
      period_debits: "100.0000",
      period_credits: "0.0000",
      closing_balance: "300.0000",
      closing_balance_signed: "300.0000",
    },
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.reports.view", "accounting.cash_bank.view"]);
  state.report = { status: "loading", data: null };
  state.cashBank = { status: "ready", items: [option(9, "1010", "Bank")] };
  state.allAccounts = { status: "ready", items: [option(9, "1010", "Bank")] };
  state.search = {};
  state.lastFilters = null;
});

describe("CashBankReportView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<CashBankReportView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the report without the reports permission", () => {
    state.perms = new Set<string>();

    render(<CashBankReportView />);

    expect(screen.getByText("Reports unavailable")).toBeInTheDocument();
  });

  it("tells the user when no cash or bank accounts are classified", () => {
    state.cashBank = { status: "ready", items: [] };

    render(<CashBankReportView />);

    expect(screen.getByText("No accounts available")).toBeInTheDocument();
  });

  it("asks for an account when none is selected", () => {
    render(<CashBankReportView />);

    expect(screen.getByText("Select an account")).toBeInTheDocument();
  });

  it("renders the ledger movements from the backend", () => {
    state.search = { account_id: "9" };
    state.report = readyReport();

    render(<CashBankReportView />);

    expect(screen.getAllByText("1010 — Bank").length).toBeGreaterThan(0);
    expect(screen.getByText("Deposit")).toBeInTheDocument();
    expect(screen.getByText("Closing balance")).toBeInTheDocument();
  });

  it("seeds the account and period from the URL", () => {
    state.search = { account_id: "9", from: "2026-01-01", to: "2026-03-31" };

    render(<CashBankReportView />);

    expect(state.lastFilters).toEqual({ account_id: 9, from: "2026-01-01", to: "2026-03-31" });
  });

  it("falls back to the full account list when the role lacks the cash/bank permission", () => {
    state.perms = new Set(["accounting.reports.view", "accounts.view"]);
    state.cashBank = { status: "error", items: [], message: "no cash/bank access" };
    state.allAccounts = { status: "ready", items: [option(99, "1090", "Petty Cash")] };

    render(<CashBankReportView />);

    const select = screen.getByLabelText("Account");
    expect(within(select).getByRole("option", { name: /Petty Cash/ })).toBeInTheDocument();
  });
});
