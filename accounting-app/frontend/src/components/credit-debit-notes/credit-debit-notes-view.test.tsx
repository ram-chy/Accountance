import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CreditDebitNotesView } from "./credit-debit-notes-view";

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

vi.mock("@/lib/suppliers/use-supplier-options", () => ({
  useSupplierOptions: () => ({ status: "ready", items: [] }),
}));

vi.mock("@/lib/credit-debit-notes/use-credit-debit-notes", () => ({
  useCreditDebitNotes: () => state.list,
  CREDIT_DEBIT_NOTE_LIST_PER_PAGE: 25,
}));

function readyList() {
  return {
    status: "ready",
    items: [
      {
        id: 5,
        note_number: "CN-0005",
        note_type: "SALES_CREDIT_NOTE",
        is_credit: true,
        label: "Sales Credit Note",
        note_date: "2026-06-30",
        status: "DRAFT",
        subtotal: "100.0000",
        discount_total: "0.0000",
        tax_total: "10.0000",
        grand_total: "110.0000",
        reason: "Returned goods",
        reference: null,
        notes: null,
        source_document_type: "sales_invoice",
        source_document_id: 11,
        source_document_number: "INV-000011",
        sales_invoice_id: 11,
        purchase_bill_id: null,
        customer_id: 4,
        supplier_id: null,
        customer: { id: 4, customer_code: "C-001", name: "Acme" },
        supplier: null,
        tax_account_id: null,
        journal_id: null,
        posted_at: null,
        lines: [],
        created_by: 2,
        posted_by: null,
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
    "accounting.credit_debit_note.view",
    "accounting.credit_debit_note.create",
    "accounting.credit_debit_note.update",
    "accounting.credit_debit_note.post",
    "accounting.credit_debit_note.delete",
  ]);
  state.list = null;
});

describe("CreditDebitNotesView", () => {
  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<CreditDebitNotesView noteType="SALES_CREDIT_NOTE" basePath="/credit-notes" />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the list without the notes permission", () => {
    state.perms = new Set<string>();

    render(<CreditDebitNotesView noteType="SALES_CREDIT_NOTE" basePath="/credit-notes" />);

    expect(screen.getByText("Notes unavailable")).toBeInTheDocument();
  });

  it("renders credit notes with a sales title and draft actions", () => {
    state.list = readyList();

    render(<CreditDebitNotesView noteType="SALES_CREDIT_NOTE" basePath="/credit-notes" />);

    expect(screen.getByRole("heading", { name: "Credit Notes" })).toBeInTheDocument();
    expect(screen.getByText("CN-0005")).toBeInTheDocument();
    expect(screen.getByText("Acme")).toBeInTheDocument();
    expect(screen.getByText("INV-000011")).toBeInTheDocument();
    expect(screen.getAllByText("Draft").length).toBeGreaterThan(0);
  });

  it("renders the debit-note title for a purchase note type", () => {
    state.list = readyList();

    render(<CreditDebitNotesView noteType="PURCHASE_DEBIT_NOTE" basePath="/debit-notes" />);

    expect(screen.getByRole("heading", { name: "Debit Notes" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "New debit note" })).toBeInTheDocument();
  });

  it("shows an error state with retry on failure", () => {
    state.list = { status: "error", items: [], perPage: 25, hasMore: false, message: "Boom", requestId: "req-7" };

    render(<CreditDebitNotesView noteType="SALES_CREDIT_NOTE" basePath="/credit-notes" />);

    expect(screen.getByText("Boom")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("shows the empty state when no notes exist", () => {
    state.list = { status: "ready", items: [], perPage: 25, hasMore: false };

    render(<CreditDebitNotesView noteType="PURCHASE_DEBIT_NOTE" basePath="/debit-notes" />);

    expect(screen.getByText("No debit notes yet")).toBeInTheDocument();
  });
});
