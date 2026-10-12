import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type {
  BankReconciliation,
  BankReconciliationDetailState,
  BankReconciliationMovement,
  BankReconciliationMovementsState,
} from "@/lib/bank-reconciliations/types";

import { ReconciliationDetailView } from "./reconciliation-detail-view";

const ALL_PERMISSIONS = [
  "accounting.bank_reconciliation.view",
  "accounting.bank_reconciliation.update",
  "accounting.bank_reconciliation.complete",
  "accounting.bank_reconciliation.reopen",
  "accounts.view",
];

const { state, api } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    detail: null as unknown,
    movements: null as unknown,
  },
  api: {
    addItem: vi.fn(),
    removeItem: vi.fn(),
    complete: vi.fn(),
    reopen: vi.fn(),
    remove: vi.fn(),
  },
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
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

vi.mock("@/lib/bank-reconciliations/use-bank-reconciliation", () => ({
  useBankReconciliation: () => state.detail,
}));

vi.mock("@/lib/bank-reconciliations/use-bank-reconciliation-movements", () => ({
  useBankReconciliationMovements: () => state.movements,
}));

vi.mock("@/lib/accounts/use-account-options", () => ({
  useAccountOptions: () => ({
    status: "ready",
    items: [{ id: 20, code: "1010", name: "Bank account", is_active: true }],
  }),
}));

vi.mock("@/lib/bank-reconciliations/api", () => ({
  addBankReconciliationItem: api.addItem,
  removeBankReconciliationItem: api.removeItem,
  completeBankReconciliation: api.complete,
  reopenBankReconciliation: api.reopen,
  deleteBankReconciliation: api.remove,
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn() },
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

function readyDetail(reconciliation: BankReconciliation): BankReconciliationDetailState {
  return { status: "ready", data: reconciliation };
}

function movement(overrides: Partial<BankReconciliationMovement> = {}): BankReconciliationMovement {
  return {
    journal_id: 11,
    journal_line_id: 42,
    journal_number: "JV-000011",
    journal_date: "2026-04-15",
    reference: "INV-1",
    description: "Receipt",
    source_type: "cash_bank_transaction",
    source_id: 5,
    debit: "100.0000",
    credit: null,
    amount: "100.0000",
    direction: "DEBIT",
    cleared: false,
    ...overrides,
  };
}

function readyMovements(items: BankReconciliationMovement[]): BankReconciliationMovementsState {
  return { status: "ready", items };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(ALL_PERMISSIONS);
  state.detail = readyDetail(buildReconciliation());
  state.movements = readyMovements([
    movement({ journal_line_id: 42, cleared: true, description: "Cleared line" }),
    movement({ journal_line_id: 43, cleared: false, description: "Open line" }),
  ]);
  api.addItem.mockReset();
  api.removeItem.mockReset();
  api.complete.mockReset();
  api.reopen.mockReset();
  api.remove.mockReset();
});

describe("ReconciliationDetailView", () => {
  it("renders the authoritative figures and the resolved account label", () => {
    render(<ReconciliationDetailView reconciliationId={3} />);

    expect(screen.getByText("1010 — Bank account")).toBeInTheDocument();
    expect(screen.getAllByText("Reconciled balance").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Statement closing").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Difference").length).toBeGreaterThan(0);
  });

  it("offers Clear for an uncleared movement and no Remove for a cleared one without a known id", () => {
    render(<ReconciliationDetailView reconciliationId={3} />);

    expect(screen.getAllByRole("button", { name: "Clear" }).length).toBeGreaterThan(0);
    expect(screen.queryByRole("button", { name: "Remove" })).not.toBeInTheDocument();
    expect(screen.getByText(/cleared in an earlier session/)).toBeInTheDocument();
  });

  it("clears a movement through the API", async () => {
    const user = userEvent.setup();
    api.addItem.mockResolvedValue({ id: 88 });

    render(<ReconciliationDetailView reconciliationId={3} />);
    await user.click(screen.getAllByRole("button", { name: "Clear" })[0]);

    expect(api.addItem).toHaveBeenCalledWith(3, { journal_line_id: 43 });
  });

  it("offers Complete only with the permission and opens the confirmation", async () => {
    const user = userEvent.setup();

    const { unmount } = render(<ReconciliationDetailView reconciliationId={3} />);
    expect(screen.getByRole("button", { name: "Complete" })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Complete" }));
    expect(screen.getByRole("dialog", { name: "Complete reconciliation" })).toBeInTheDocument();
    unmount();

    state.perms = new Set(["accounting.bank_reconciliation.view"]);
    render(<ReconciliationDetailView reconciliationId={3} />);
    expect(screen.queryByRole("button", { name: "Complete" })).not.toBeInTheDocument();
  });

  it("offers Reopen for a completed reconciliation", async () => {
    state.detail = readyDetail(
      buildReconciliation({ status: "RECONCILED", is_reconciled: true }),
    );
    const user = userEvent.setup();

    render(<ReconciliationDetailView reconciliationId={3} />);
    await user.click(screen.getByRole("button", { name: "Reopen" }));

    expect(screen.getByRole("dialog", { name: "Reopen reconciliation" })).toBeInTheDocument();
  });

  it("offers Delete only for a draft reconciliation", async () => {
    state.detail = readyDetail(buildReconciliation({ status: "DRAFT" }));
    const user = userEvent.setup();

    render(<ReconciliationDetailView reconciliationId={3} />);
    await user.click(screen.getByRole("button", { name: "Delete" }));

    expect(screen.getByRole("dialog", { name: "Delete reconciliation" })).toBeInTheDocument();
  });

  it("shows the not-found state for a missing reconciliation", () => {
    state.detail = { status: "not-found" } as BankReconciliationDetailState;

    render(<ReconciliationDetailView reconciliationId={3} />);

    expect(screen.getByText("Reconciliation not found")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Back to reconciliations" })).toHaveAttribute(
      "href",
      "/bank-reconciliations",
    );
  });
});
