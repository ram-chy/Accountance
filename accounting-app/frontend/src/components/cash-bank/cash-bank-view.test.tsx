import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CashBankAccount } from "@/lib/cash-bank/types";

import { CashBankView } from "./cash-bank-view";

const ALL_PERMISSIONS = [
  "accounting.cash_bank.view",
  "accounting.cash_bank.update",
  "accounting.ledger.view",
];

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    list: null as unknown,
    balance: { status: "ready", balance: "500.0000" } as unknown,
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

vi.mock("@/lib/cash-bank/use-cash-bank-accounts", () => ({
  useCashBankAccountList: () => state.list,
  useCashBankAccountOptions: () => ({ status: "ready", items: [] }),
}));

vi.mock("@/lib/cash-bank/use-account-balance", () => ({
  useAccountBalance: () => state.balance,
}));

vi.mock("@/lib/cash-bank/api", () => ({
  updateCashBankAccount: vi.fn(),
  deleteBankDetails: vi.fn(),
  activateBankDetails: vi.fn(),
  deactivateBankDetails: vi.fn(),
}));

function buildAccount(overrides: Partial<CashBankAccount> = {}): CashBankAccount {
  return {
    id: 1,
    code: "1000",
    name: "Cash on hand",
    account_type: "ASSET",
    normal_balance: "DEBIT",
    cash_bank_kind: "CASH",
    is_active: true,
    description: null,
    bank_account: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function readyState(items: CashBankAccount[]): unknown {
  return { status: "ready", items, perPage: 100, hasMore: false };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(ALL_PERMISSIONS);
  state.list = readyState([buildAccount()]);
  state.balance = { status: "ready", balance: "500.0000" };
});

describe("CashBankView", () => {
  it("renders classified accounts and their bank details", () => {
    state.list = readyState([
      buildAccount({ id: 1, code: "1000", name: "Cash on hand" }),
      buildAccount({
        id: 2,
        code: "1010",
        name: "Operating bank",
        cash_bank_kind: "BANK",
        bank_account: {
          account_name: "Acme",
          bank_name: "First National",
          account_number: "123",
          branch: null,
          bank_identifier: null,
          is_active: true,
        },
      }),
    ]);

    render(<CashBankView />);

    expect(screen.getByRole("heading", { name: /Cash & banking/ })).toBeInTheDocument();
    expect(screen.getAllByText("Cash on hand").length).toBeGreaterThan(0);
    expect(screen.getAllByText("First National").length).toBeGreaterThan(0);
  });

  it("shows a ledger balance from the per-account read", () => {
    render(<CashBankView />);

    expect(screen.getAllByText("500.00").length).toBeGreaterThan(0);
  });

  it("shows the empty state when there are no cash or bank accounts", () => {
    state.list = readyState([]);

    render(<CashBankView />);

    expect(screen.getByText("No cash or bank accounts")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<CashBankView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the manage action without the update permission", () => {
    state.perms = new Set(["accounting.cash_bank.view", "accounting.ledger.view"]);

    render(<CashBankView />);

    expect(screen.queryByRole("button", { name: "Manage" })).not.toBeInTheDocument();
  });

  it("opens the manage dialog from a row", async () => {
    const user = userEvent.setup();

    render(<CashBankView />);

    await user.click(screen.getAllByRole("button", { name: "Manage" })[0]);

    expect(screen.getByRole("dialog", { name: "Manage 1000" })).toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.list = {
      status: "error",
      items: [],
      perPage: 0,
      hasMore: false,
      message: "Server exploded.",
    };

    render(<CashBankView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });
});
