import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { GeneralLedgerView } from "./general-ledger-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>(),
    ledger: null as unknown,
    accounts: { status: "ready", items: [] as Array<{ id: number; code: string; name: string; is_active: boolean }> },
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

vi.mock("@/lib/accounts/use-account-options", () => ({
  useAccountOptions: () => state.accounts,
}));

vi.mock("@/lib/reports/use-general-ledger", () => ({
  useGeneralLedger: (options: { filters: unknown }) => {
    state.lastFilters = options.filters;
    return state.ledger;
  },
}));

function accountOption(id: number, code: string, name: string) {
  return { id, code, name, is_active: true };
}

function readyLedger() {
  return {
    status: "ready",
    data: {
      account: {
        id: 1,
        code: "1000",
        name: "Cash",
        account_type: "ASSET",
        normal_balance: "DEBIT",
        is_active: true,
      },
      period: { from: "2026-01-01", to: "2026-12-31" },
      base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
      opening_balance: "0.0000",
      rows: [
        {
          journal_id: 5,
          journal_line_id: 50,
          journal_number: "JNL-000005",
          journal_date: "2026-01-15",
          reference: "REF-1",
          description: "Opening entry",
          source_type: "MANUAL",
          source_id: null,
          debit: "500.0000",
          credit: "0.0000",
          running_balance: "500.0000",
          currency_id: null,
          exchange_rate: null,
          foreign_debit: null,
          foreign_credit: null,
        },
      ],
      period_debits: "500.0000",
      period_credits: "0.0000",
      closing_balance: "500.0000",
      closing_balance_signed: "500.0000",
    },
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.reports.view", "accounts.view"]);
  state.ledger = { status: "loading", data: null };
  state.accounts = { status: "ready", items: [accountOption(1, "1000", "Cash")] };
  state.search = {};
  state.lastFilters = null;
});

describe("GeneralLedgerView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<GeneralLedgerView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the report without the reports permission", () => {
    state.perms = new Set(["accounts.view"]);

    render(<GeneralLedgerView />);

    expect(screen.getByText("Reports unavailable")).toBeInTheDocument();
  });

  it("asks for an account when none is selected", () => {
    render(<GeneralLedgerView />);

    expect(screen.getByText("Select an account")).toBeInTheDocument();
  });

  it("renders the movements, running balance and closing figures from the backend", () => {
    state.search = { account_id: "1" };
    state.ledger = readyLedger();

    render(<GeneralLedgerView />);

    expect(screen.getAllByText("1000 — Cash").length).toBeGreaterThan(0);
    expect(screen.getByText("JNL-000005")).toBeInTheDocument();
    expect(screen.getByText("Opening entry")).toBeInTheDocument();
    expect(screen.getByText("Closing balance")).toBeInTheDocument();
  });

  it("seeds the account and period from the URL drill-down", () => {
    state.search = { account_id: "7", from: "2026-01-01", to: "2026-06-30" };

    render(<GeneralLedgerView />);

    expect(state.lastFilters).toEqual({ account_id: 7, from: "2026-01-01", to: "2026-06-30" });
  });

  it("applies the selected account from the picker", async () => {
    state.accounts = {
      status: "ready",
      items: [accountOption(1, "1000", "Cash"), accountOption(3, "3000", "Capital")],
    };

    render(<GeneralLedgerView />);

    await userEvent.selectOptions(screen.getByLabelText("Account"), "3");
    await userEvent.click(screen.getByRole("button", { name: "Apply" }));

    expect(state.lastFilters).toEqual({ account_id: 3, from: "", to: "" });
  });

  it("lists accounts beyond the first page so a large chart of accounts is selectable", () => {
    const items = Array.from({ length: 60 }, (_, index) =>
      accountOption(index + 1, `1${String(index).padStart(3, "0")}`, `Account ${index + 1}`),
    );
    state.accounts = { status: "ready", items };

    render(<GeneralLedgerView />);

    const select = screen.getByLabelText("Account");
    // 60 accounts plus the "Select an account…" placeholder.
    expect(within(select).getAllByRole("option")).toHaveLength(61);
    expect(within(select).getByRole("option", { name: /Account 60/ })).toBeInTheDocument();
  });

  it("surfaces a load error with a retry affordance", () => {
    state.search = { account_id: "1" };
    state.ledger = { status: "error", data: null, message: "Boom", requestId: "req-1" };

    render(<GeneralLedgerView />);

    expect(screen.getByText("Boom")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });
});
