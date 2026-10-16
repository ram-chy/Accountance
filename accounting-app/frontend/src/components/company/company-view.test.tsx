import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { SessionCompany } from "@/lib/auth/types";

import { CompanyView } from "./company-view";

const { state } = vi.hoisted(() => ({
  state: {
    activeCompany: null as SessionCompany | null,
    perms: new Set<string>([]),
    settings: null as unknown,
  },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({ activeCompany: state.activeCompany, refresh: vi.fn() }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({
    can: (name: string) => state.perms.has(name),
  }),
}));

vi.mock("@/lib/company/use-company-settings", () => ({
  useCompanySettings: () => state.settings,
}));

vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function buildCompany(overrides: Partial<SessionCompany> = {}): SessionCompany {
  return {
    id: 5,
    name: "Acme Ltd",
    legal_name: null,
    registration_number: null,
    tax_number: null,
    email: null,
    phone: null,
    website: null,
    address: {
      line_1: null,
      line_2: null,
      city: null,
      state: null,
      postal_code: null,
      country_code: "US",
    },
    timezone: "UTC",
    date_format: "Y-m-d",
    is_active: true,
    is_default: true,
    settings: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

beforeEach(() => {
  state.activeCompany = buildCompany();
  state.perms = new Set(["companies.view"]);
  state.settings = { status: "unavailable" };
});

describe("CompanyView", () => {
  it("prompts for a company when none is active", () => {
    state.activeCompany = null;

    render(<CompanyView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("shows the profile read-only without the update permission", () => {
    render(<CompanyView />);

    expect(screen.getByText("Company profile")).toBeInTheDocument();
    expect(
      screen.getByText("Editing the company profile requires the company update permission."),
    ).toBeInTheDocument();
  });

  it("hides the lifecycle controls without the delete permission", () => {
    render(<CompanyView />);

    expect(screen.queryByRole("button", { name: "Deactivate company" })).not.toBeInTheDocument();
  });

  it("shows the deactivate control for an active company with the delete permission", () => {
    state.perms = new Set(["companies.view", "companies.delete"]);

    render(<CompanyView />);

    expect(screen.getByRole("button", { name: "Deactivate company" })).toBeInTheDocument();
  });

  it("offers reactivation when the active company is inactive", () => {
    state.perms = new Set(["companies.view", "companies.delete"]);
    state.activeCompany = buildCompany({ is_active: false });

    render(<CompanyView />);

    expect(screen.getByRole("button", { name: "Reactivate company" })).toBeInTheDocument();
    expect(screen.getByText("Inactive")).toBeInTheDocument();
  });

  it("hides the settings section without the settings view permission", () => {
    render(<CompanyView />);

    expect(screen.queryByText("Numbering, terms and defaults")).not.toBeInTheDocument();
  });

  it("renders the settings section when the permission is present", () => {
    state.perms = new Set(["companies.view", "companies.settings.view"]);
    state.settings = { status: "loading" };

    render(<CompanyView />);

    expect(screen.getByText("Numbering, terms and defaults")).toBeInTheDocument();
  });

  it("links to the members and users screens", () => {
    render(<CompanyView />);

    expect(screen.getByRole("link", { name: "Members" })).toHaveAttribute(
      "href",
      "/settings/company/members",
    );
    expect(screen.getByRole("link", { name: "Users" })).toHaveAttribute(
      "href",
      "/settings/users",
    );
  });
});
