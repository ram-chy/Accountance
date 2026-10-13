import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CustomerReceiptsView } from "./customer-receipts-view";

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

vi.mock("@/lib/customers/use-customer-options", () => ({
  useCustomerOptions: () => ({ status: "ready", items: [] }),
}));

vi.mock("@/lib/customer-receipts/use-customer-receipts", () => ({
  useCustomerReceipts: () => state.list,
  CUSTOMER_RECEIPT_LIST_PER_PAGE: 25,
}));

function readyList() {
  return {
    status: "ready",
    items: [
      {
        id: 5,
        receipt_number: "RCP-0005",
        customer_id: 4,
        receipt_date: "2026-06-30",
        amount: "600.0000",
        status: "DRAFT",
        payment_account_id: 2,
        reference: "WIRE-1",
        notes: null,
        allocations: [],
        customer: { id: 4, customer_code: "C-001", name: "Acme" },
        journal_id: null,
        posted_at: null,
        created_at: "2026-06-30T10:00:00Z",
        updated_at: "2026-06-30T10:00:00Z",
      },
    ],
    perPage: 25,
    hasMore: false,
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set([
    "customer.receipts.view",
    "customer.receipts.update",
    "customer.receipts.post",
    "customer.receipts.delete",
  ]);
  state.list = null;
});

describe("CustomerReceiptsView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<CustomerReceiptsView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the list without the receipts permission", () => {
    state.perms = new Set<string>();

    render(<CustomerReceiptsView />);

    expect(screen.getByText("Receipts unavailable")).toBeInTheDocument();
  });

  it("renders receipts with draft actions", () => {
    state.list = readyList();

    render(<CustomerReceiptsView />);

    expect(screen.getByRole("heading", { name: "Customer Receipts" })).toBeInTheDocument();
    expect(screen.getByText("RCP-0005")).toBeInTheDocument();
    expect(screen.getByText("Acme")).toBeInTheDocument();
    expect(screen.getAllByText("Draft").length).toBeGreaterThan(0);
    expect(screen.getByText("WIRE-1")).toBeInTheDocument();
  });

  it("shows an error state with retry on failure", () => {
    state.list = { status: "error", items: [], perPage: 25, hasMore: false, message: "Boom", requestId: "req-7" };

    render(<CustomerReceiptsView />);

    expect(screen.getByText("Boom")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("shows the empty state when no receipts exist", () => {
    state.list = { status: "ready", items: [], perPage: 25, hasMore: false };

    render(<CustomerReceiptsView />);

    expect(screen.getByText("No customer receipts yet")).toBeInTheDocument();
  });
});