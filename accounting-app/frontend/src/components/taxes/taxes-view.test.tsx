import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Tax, TaxListState } from "@/lib/taxes/types";

import { TaxesView } from "./taxes-view";

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

vi.mock("@/lib/taxes/use-taxes", () => ({
  useTaxList: () => state.list,
}));

vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function buildTax(overrides: Partial<Tax> = {}): Tax {
  return {
    id: 1,
    code: "VAT",
    name: "VAT standard",
    description: null,
    tax_type: "OUTPUT",
    calculation_basis: "EXCLUSIVE",
    is_active: true,
    applies_to_sales: true,
    applies_to_purchase: false,
    current_rate: "20.0000",
    account_mapping: null,
    created_by: 1,
    updated_by: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function readyState(items: Tax[]): TaxListState {
  return { status: "ready", items };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.tax.view"]);
  state.list = readyState([buildTax()]);
});

describe("TaxesView", () => {
  it("renders taxes and links each row to its detail route", () => {
    state.list = readyState([buildTax({ id: 3 }), buildTax({ id: 4, code: "GST", name: "GST" })]);

    render(<TaxesView />);

    expect(screen.getByRole("heading", { name: "Taxes" })).toBeInTheDocument();
    expect(screen.getAllByText(/20\.0000%/).length).toBeGreaterThan(0);
    expect(screen.getAllByRole("link", { name: "Manage" })[0]).toHaveAttribute(
      "href",
      "/taxes/3",
    );
  });

  it("shows the empty state when there are no taxes", () => {
    state.list = readyState([]);

    render(<TaxesView />);

    expect(screen.getByText("No taxes yet")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<TaxesView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the list without the view permission", () => {
    state.perms = new Set<string>();

    render(<TaxesView />);

    expect(screen.getByText("Taxes unavailable")).toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.list = { status: "error", items: [], message: "Server exploded." };

    render(<TaxesView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("shows the create action only with the create permission", () => {
    state.perms = new Set(["accounting.tax.view", "accounting.tax.create"]);
    state.list = readyState([]);

    render(<TaxesView />);

    expect(screen.getAllByRole("button", { name: "New tax" }).length).toBeGreaterThan(0);
  });
});
