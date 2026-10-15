import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CompanyFxSetting } from "@/lib/fx-settings/types";

import { FxSettingsView } from "./fx-settings-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    setting: null as unknown,
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

vi.mock("@/lib/fx-settings/use-fx-setting", () => ({
  useFxSetting: () => state.setting,
}));

vi.mock("@/lib/accounts/use-account-type-options", () => ({
  useAccountTypeOptions: () => ({
    status: "ready",
    items: [
      { id: 30, code: "4100", name: "FX gain", account_type: "REVENUE", is_active: true },
      { id: 40, code: "6100", name: "FX loss", account_type: "EXPENSE", is_active: true },
    ],
  }),
}));

vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function buildSetting(overrides: Partial<CompanyFxSetting> = {}): CompanyFxSetting {
  return {
    company_id: 1,
    realized_gain_account_id: 30,
    realized_loss_account_id: 40,
    is_configured: true,
    realized_gain_account: { id: 30, code: "4100", name: "FX gain" },
    realized_loss_account: { id: 40, code: "6100", name: "FX loss" },
    updated_at: "2026-04-01T00:00:00Z",
    ...overrides,
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["accounting.fx.update"]);
  state.setting = { status: "ready", setting: buildSetting() };
});

describe("FxSettingsView", () => {
  it("renders the configured pair with both pickers", () => {
    render(<FxSettingsView />);

    expect(screen.getByRole("heading", { name: "Currency settings" })).toBeInTheDocument();
    expect(screen.getByText("Configured")).toBeInTheDocument();
    expect(screen.getByLabelText("Realised gain account")).toHaveValue("30");
    expect(screen.getByLabelText("Realised loss account")).toHaveValue("40");
  });

  it("shows the not-configured state", () => {
    state.setting = {
      status: "ready",
      setting: buildSetting({
        realized_gain_account_id: null,
        realized_loss_account_id: null,
        is_configured: false,
        realized_gain_account: null,
        realized_loss_account: null,
      }),
    };

    render(<FxSettingsView />);

    expect(screen.getByText("Not configured")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<FxSettingsView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the form without the fx.update permission", () => {
    state.perms = new Set<string>();

    render(<FxSettingsView />);

    expect(screen.getByText("Currency settings unavailable")).toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.setting = { status: "error", setting: null, message: "Server exploded." };

    render(<FxSettingsView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });
});
