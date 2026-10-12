import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { BankReconciliation, BankReconciliationListState } from "@/lib/bank-reconciliations/types";

import { BankReconciliationsView } from "./bank-reconciliations-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    list: null as unknown,
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

vi.mock("@/lib/bank-reconciliations/use-bank-reconciliations", () => ({
  BANK_RECONCILIATION_LIST_PER_PAGE: 25,
  useBankReconciliationList: () => state.list,
}));

vi.mock("@/lib/accounts/use-account-options", () => ({
  useAccountOptions: () => ({
    status: "ready",
    items: [{ id: 20, code: "1010", name: "Bank account", is_active: true }],
  }),
}));

function buildReconciliation(
  overrides: Partial<BankReconciliation> = {},
): BankReconciliation {
  return {
    id: 3,
    company_id: 1,
    bank_account_id: 7,
    account_id: 20,
    from_date: "2026-04-01",
    to_date: "2026-04-30",
    status: "IN_PROGRESS",
    statement_opening_balance: "1000.0000",
    statement_closing_balance: "1200.0000",
    ledger_opening: "1000.0000",
    ledger_closing: "1150.0000",
    period_debits: "300.0000",
    period_credits: "150.0000",
    cleared_debits: "200.0000",
    cleared_credits: "100.0000",
    uncleared_debits: "100.0000",
    uncleared_credits: "50.0000",
    reconciled_balance: "1200.0000",
    difference: "0.0000",
    total_movements: 4,
    cleared_movements: 2,
    uncleared_movements: 2,
    is_reconciled: false,
    previous_reconciled_closing: null,
    opening_balance_difference: null,
    notes: null,
    completed_at: null,
    reopened_at: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function readyState(items: BankReconciliation[]): BankReconciliationListState {
  return { status: "ready", items, perPage: 25, hasMore: false };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.bank_reconciliation.view", "accounts.view"]);
  state.list = readyState([buildReconciliation()]);
});

describe("BankReconciliationsView", () => {
  it("renders reconciliations with the resolved account label", () => {
    state.list = readyState([
      buildReconciliation({ id: 3 }),
      buildReconciliation({ id: 4, status: "RECONCILED", is_reconciled: true }),
    ]);

    render(<BankReconciliationsView />);

    expect(screen.getByRole("heading", { name: "Bank reconciliations" })).toBeInTheDocument();
    expect(screen.getAllByText("1010 — Bank account").length).toBeGreaterThan(0);
    expect(screen.getAllByText("In progress").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Reconciled").length).toBeGreaterThan(0);
  });

  it("shows the empty state when there are no reconciliations", () => {
    state.list = readyState([]);

    render(<BankReconciliationsView />);

    expect(screen.getByText("No bank reconciliations yet")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<BankReconciliationsView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the list without the view permission", () => {
    state.perms = new Set<string>();

    render(<BankReconciliationsView />);

    expect(screen.getByText("Bank reconciliations unavailable")).toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.list = {
      status: "error",
      items: [],
      perPage: 25,
      hasMore: false,
      message: "Server exploded.",
    };

    render(<BankReconciliationsView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("links each row to its detail route", () => {
    render(<BankReconciliationsView />);

    const links = screen.getAllByRole("link", { name: "View" });
    expect(links[0]).toHaveAttribute("href", "/bank-reconciliations/3");
  });
});
