import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Account, AccountListState } from "@/lib/accounts/types";

import { AccountsView } from "./accounts-view";

const ALL_PERMISSIONS = [
  "accounts.view",
  "accounts.create",
  "accounts.update",
  "accounts.activate",
  "accounts.deactivate",
  "accounts.delete",
];

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    list: null as unknown,
    types: { status: "ready", data: [] } as unknown,
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

vi.mock("@/lib/accounts/use-account-list", () => ({
  ACCOUNT_LIST_PER_PAGE: 50,
  useAccountList: () => state.list,
  useAccountTypes: () => state.types,
}));

vi.mock("@/lib/accounts/api", () => ({
  createAccount: vi.fn(),
  updateAccount: vi.fn(),
  deleteAccount: vi.fn(),
  activateAccount: vi.fn(),
  deactivateAccount: vi.fn(),
}));

function buildAccount(overrides: Partial<Account> = {}): Account {
  return {
    id: 1,
    code: "1000",
    name: "Cash",
    account_type: "ASSET",
    cash_bank_kind: "CASH",
    is_cash_bank_account: true,
    normal_balance: "DEBIT",
    is_contra: false,
    parent_id: null,
    description: null,
    is_active: true,
    is_system: false,
    has_journal_history: false,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function readyState(items: Account[]): AccountListState {
  return { status: "ready", items, perPage: 50, hasMore: false };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(ALL_PERMISSIONS);
  state.list = readyState([buildAccount()]);
  state.types = { status: "ready", data: [] };
});

describe("AccountsView", () => {
  it("renders accounts from the loaded list", () => {
    state.list = readyState([
      buildAccount({ id: 1, code: "1000", name: "Cash" }),
      buildAccount({ id: 2, code: "4000", name: "Sales", account_type: "REVENUE" }),
    ]);

    render(<AccountsView />);

    expect(screen.getByRole("heading", { name: "Chart of accounts" })).toBeInTheDocument();
    expect(screen.getAllByText("1000").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Cash").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Sales").length).toBeGreaterThan(0);
  });

  it("shows the empty state when there are no accounts", () => {
    state.list = readyState([]);

    render(<AccountsView />);

    expect(screen.getByText("No accounts yet")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<AccountsView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the create button without the create permission", () => {
    state.perms = new Set(["accounts.view"]);

    render(<AccountsView />);

    expect(screen.queryByRole("button", { name: "New account" })).not.toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.list = {
      status: "error",
      items: [],
      perPage: 50,
      hasMore: false,
      message: "Server exploded.",
    };

    render(<AccountsView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("opens the create dialog from the header action", async () => {
    const user = userEvent.setup();

    render(<AccountsView />);

    await user.click(screen.getByRole("button", { name: "New account" }));

    expect(screen.getByRole("dialog", { name: "New account" })).toBeInTheDocument();
  });

  it("opens the deactivate confirmation for a row", async () => {
    const user = userEvent.setup();

    render(<AccountsView />);

    await user.click(screen.getAllByRole("button", { name: "Deactivate" })[0]);

    expect(
      screen.getByRole("dialog", { name: "Deactivate account" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/will stop accepting new journal postings/),
    ).toBeInTheDocument();
  });
});