import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { SupplierPaymentsView } from "./supplier-payments-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>(),
    list: null as unknown,
  },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({ activeCompany: state.hasCompany ? { id: 1, name: "Acme Ltd" } : null }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({ can: (name: string) => state.perms.has(name) }),
}));

vi.mock("@/lib/suppliers/use-supplier-options", () => ({
  useSupplierOptions: () => ({ status: "ready", items: [] }),
}));

vi.mock("@/lib/supplier-payments/use-supplier-payments", () => ({
  useSupplierPayments: () => state.list,
  SUPPLIER_PAYMENT_LIST_PER_PAGE: 25,
}));

function readyList() {
  return {
    status: "ready",
    items: [
      {
        id: 5,
        payment_number: "PAY-0005",
        supplier_id: 3,
        payment_date: "2026-05-02",
        amount: "600.0000",
        status: "DRAFT",
        payment_account_id: 2,
        reference: "WIRE-1",
        notes: null,
        allocations: [],
        supplier: { id: 3, supplier_code: "S-001", name: "Parts Ltd" },
        journal_id: null,
        posted_at: null,
        created_at: "2026-05-02T10:00:00Z",
        updated_at: "2026-05-02T10:00:00Z",
      },
    ],
    perPage: 25,
    hasMore: false,
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set([
    "supplier.payments.view",
    "supplier.payments.create",
    "supplier.payments.update",
    "supplier.payments.post",
    "supplier.payments.delete",
  ]);
  state.list = null;
});

describe("SupplierPaymentsView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<SupplierPaymentsView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the list without the payments permission", () => {
    state.perms = new Set<string>();

    render(<SupplierPaymentsView />);

    expect(screen.getByText("Payments unavailable")).toBeInTheDocument();
  });

  it("renders payments with draft actions", () => {
    state.list = readyList();

    render(<SupplierPaymentsView />);

    expect(screen.getByRole("heading", { name: "Supplier Payments" })).toBeInTheDocument();
    expect(screen.getByText("PAY-0005")).toBeInTheDocument();
    expect(screen.getByText("Parts Ltd")).toBeInTheDocument();
    expect(screen.getAllByText("Draft").length).toBeGreaterThan(0);
    expect(screen.getByText("WIRE-1")).toBeInTheDocument();
  });

  it("shows an error state with retry on failure", () => {
    state.list = { status: "error", items: [], perPage: 25, hasMore: false, message: "Boom", requestId: "req-7" };

    render(<SupplierPaymentsView />);

    expect(screen.getByText("Boom")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("shows the empty state when no payments exist", () => {
    state.list = { status: "ready", items: [], perPage: 25, hasMore: false };

    render(<SupplierPaymentsView />);

    expect(screen.getByText("No supplier payments yet")).toBeInTheDocument();
  });
});
