import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CashBankTransaction } from "@/lib/cash-bank/types";

import { CashBankTransactionsView } from "./cash-bank-transactions-view";

const ALL_PERMISSIONS = [
  "accounting.cash_bank.view",
  "accounting.cash_bank.create",
  "accounting.cash_bank.update",
  "accounting.cash_bank.post",
  "accounting.cash_bank.delete",
];

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    list: null as unknown,
  },
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () => ({ get: () => null }),
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

vi.mock("@/lib/cash-bank/use-cash-bank-transactions", () => ({
  useCashBankTransactionList: () => state.list,
}));

vi.mock("@/lib/cash-bank/use-cash-bank-accounts", () => ({
  useCashBankAccountOptions: () => ({ status: "ready", items: [] }),
}));

vi.mock("@/lib/accounts/use-account-options", () => ({
  useAccountOptions: () => ({ status: "ready", items: [] }),
}));

vi.mock("@/lib/cash-bank/api", () => ({
  createCashBankTransaction: vi.fn(),
  updateCashBankTransaction: vi.fn(),
  deleteCashBankTransaction: vi.fn(),
  postCashBankTransaction: vi.fn(),
}));

function buildTransaction(overrides: Partial<CashBankTransaction> = {}): CashBankTransaction {
  return {
    id: 10,
    transaction_number: "CBT-000010",
    transaction_type: "DEPOSIT",
    transaction_date: "2027-02-01",
    source_account_id: 20,
    destination_account_id: 1,
    amount: "500.0000",
    status: "DRAFT",
    reference: null,
    notes: null,
    source_account: { id: 20, code: "4000", name: "Sales" },
    destination_account: { id: 1, code: "1000", name: "Cash on hand" },
    journal_id: null,
    posted_at: null,
    created_by: 1,
    posted_by: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function readyState(items: CashBankTransaction[]): unknown {
  return { status: "ready", items, perPage: 25, hasMore: false };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(ALL_PERMISSIONS);
  state.list = readyState([buildTransaction()]);
});

describe("CashBankTransactionsView", () => {
  it("renders transactions with their two sides", () => {
    render(<CashBankTransactionsView />);

    expect(
      screen.getByRole("heading", { name: /Cash & bank transactions/ }),
    ).toBeInTheDocument();
    expect(screen.getAllByText("CBT-000010").length).toBeGreaterThan(0);
    expect(screen.getAllByText(/Sales/).length).toBeGreaterThan(0);
  });

  it("shows the empty state when there are no transactions", () => {
    state.list = readyState([]);

    render(<CashBankTransactionsView />);

    expect(screen.getByText("No transactions yet")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<CashBankTransactionsView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the create button without the create permission", () => {
    state.perms = new Set(["accounting.cash_bank.view"]);

    render(<CashBankTransactionsView />);

    expect(screen.queryByRole("button", { name: "New transaction" })).not.toBeInTheDocument();
  });

  it("opens the create dialog from the header action", async () => {
    const user = userEvent.setup();

    render(<CashBankTransactionsView />);

    await user.click(screen.getByRole("button", { name: "New transaction" }));

    expect(
      screen.getByRole("dialog", { name: "New cash/bank transaction" }),
    ).toBeInTheDocument();
  });

  it("opens the detail dialog from a row", async () => {
    const user = userEvent.setup();

    render(<CashBankTransactionsView />);

    await user.click(screen.getAllByRole("button", { name: "View" })[0]);

    expect(screen.getByRole("dialog", { name: "CBT-000010" })).toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.list = {
      status: "error",
      items: [],
      perPage: 0,
      hasMore: false,
      message: "Server exploded.",
    };

    render(<CashBankTransactionsView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });
});
