import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { ExchangeRate, ExchangeRateListState } from "@/lib/exchange-rates/types";

import { ExchangeRatesView } from "./exchange-rates-view";

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

vi.mock("@/lib/exchange-rates/use-exchange-rates", () => ({
  useExchangeRateList: () => state.list,
}));

vi.mock("@/lib/currencies/use-currency-options", () => ({
  useCurrencyOptions: () => ({
    status: "ready",
    items: [
      { id: 1, code: "USD", name: "US Dollar", symbol: "$" },
      { id: 2, code: "INR", name: "Indian Rupee", symbol: "₹" },
    ],
  }),
}));

vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function buildRate(overrides: Partial<ExchangeRate> = {}): ExchangeRate {
  return {
    id: 1,
    from_currency_id: 1,
    to_currency_id: 2,
    from_currency: "USD",
    to_currency: "INR",
    pair: "USD/INR",
    effective_date: "2026-04-01",
    rate: "83.5000000000",
    is_active: true,
    source: "ECB",
    created_by: 1,
    updated_by: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function readyState(items: ExchangeRate[]): ExchangeRateListState {
  return { status: "ready", items };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.exchange_rate.view"]);
  state.list = readyState([buildRate()]);
});

describe("ExchangeRatesView", () => {
  it("renders rates with their direction and links each row to its detail route", () => {
    state.list = readyState([buildRate({ id: 3 }), buildRate({ id: 4, pair: "EUR/INR" })]);

    render(<ExchangeRatesView />);

    expect(screen.getByRole("heading", { name: "Exchange rates" })).toBeInTheDocument();
    expect(screen.getAllByText("1 USD = 83.5000000000 INR").length).toBeGreaterThan(0);
    expect(screen.getAllByRole("link", { name: "View" })[0]).toHaveAttribute(
      "href",
      "/exchange-rates/3",
    );
  });

  it("shows the empty state when there are no rates", () => {
    state.list = readyState([]);

    render(<ExchangeRatesView />);

    expect(screen.getByText("No exchange rates yet")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<ExchangeRatesView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the list without the view permission", () => {
    state.perms = new Set<string>();

    render(<ExchangeRatesView />);

    expect(screen.getByText("Exchange rates unavailable")).toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.list = { status: "error", items: [], message: "Server exploded." };

    render(<ExchangeRatesView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("shows the create action only with the create permission", () => {
    state.perms = new Set(["accounting.exchange_rate.view", "accounting.exchange_rate.create"]);
    state.list = readyState([]);

    render(<ExchangeRatesView />);

    expect(screen.getAllByRole("button", { name: "New exchange rate" }).length).toBeGreaterThan(0);
  });
});
