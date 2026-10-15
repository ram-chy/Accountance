import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Currency, CurrencyListState } from "@/lib/currencies/types";

import { CurrenciesView } from "./currencies-view";

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

vi.mock("@/lib/currencies/use-currencies", () => ({
  useCurrencyList: () => state.list,
}));

vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function buildCurrency(overrides: Partial<Currency> = {}): Currency {
  return {
    id: 1,
    code: "USD",
    name: "US Dollar",
    symbol: "$",
    decimal_precision: 2,
    is_active: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function readyState(items: Currency[]): CurrencyListState {
  return { status: "ready", items };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.currency.view"]);
  state.list = readyState([buildCurrency()]);
});

describe("CurrenciesView", () => {
  it("renders currencies and links each row to its detail route", () => {
    state.list = readyState([buildCurrency({ id: 3 }), buildCurrency({ id: 4, code: "EUR" })]);

    render(<CurrenciesView />);

    expect(screen.getByRole("heading", { name: "Currencies" })).toBeInTheDocument();
    expect(screen.getAllByText("USD").length).toBeGreaterThan(0);
    expect(screen.getAllByRole("link", { name: "View" })[0]).toHaveAttribute("href", "/currencies/3");
  });

  it("shows the empty state when there are no currencies", () => {
    state.list = readyState([]);

    render(<CurrenciesView />);

    expect(screen.getByText("No currencies yet")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<CurrenciesView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the list without the view permission", () => {
    state.perms = new Set<string>();

    render(<CurrenciesView />);

    expect(screen.getByText("Currencies unavailable")).toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.list = { status: "error", items: [], message: "Server exploded." };

    render(<CurrenciesView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("shows the create action only with the create permission", () => {
    state.perms = new Set(["accounting.currency.view", "accounting.currency.create"]);
    state.list = readyState([]);

    render(<CurrenciesView />);

    expect(screen.getAllByRole("button", { name: "New currency" }).length).toBeGreaterThan(0);
  });
});
