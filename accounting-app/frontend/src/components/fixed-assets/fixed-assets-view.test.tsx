import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { FixedAsset, FixedAssetListState } from "@/lib/fixed-assets/types";

import { FixedAssetsView } from "./fixed-assets-view";

const ALL_PERMISSIONS = [
  "accounting.fixed_asset.view",
  "accounting.fixed_asset.create",
  "accounting.fixed_asset.update",
];

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

vi.mock("@/lib/fixed-assets/use-fixed-assets", () => ({
  FIXED_ASSETS_PER_PAGE: 25,
  useFixedAssets: () => state.list,
}));

vi.mock("@/lib/fixed-asset-categories/use-fixed-asset-category-options", () => ({
  useFixedAssetCategoryOptions: () => ({ status: "ready", items: [] }),
}));

function buildAsset(overrides: Partial<FixedAsset> = {}): FixedAsset {
  return {
    id: 7,
    asset_number: "FA-000007",
    name: "Delivery van",
    description: null,
    serial_number: null,
    supplier_reference: null,
    status: "DRAFT",
    acquisition_method: "CASH",
    fixed_asset_category_id: 3,
    category: null,
    acquisition_date: "2026-01-01",
    depreciation_start_date: "2026-01-01",
    original_cost: "30000.0000",
    salvage_value: "0.0000",
    useful_life_months: 60,
    depreciation_method: "STRAIGHT_LINE",
    accumulated_depreciation: "0.0000",
    carrying_value: "30000.0000",
    monthly_charge: "500.0000",
    period_count: 60,
    remaining_period_count: 60,
    asset_account_id: 10,
    accumulated_depreciation_account_id: 11,
    depreciation_expense_account_id: 12,
    gain_on_disposal_account_id: null,
    loss_on_disposal_account_id: null,
    acquisition_account_id: 1,
    asset_account: null,
    accumulated_depreciation_account: null,
    depreciation_expense_account: null,
    acquisition_account: null,
    journal_id: null,
    journal: null,
    capitalised_at: null,
    fully_depreciated_at: null,
    disposed_at: null,
    depreciations: null,
    disposal: null,
    created_by: 1,
    updated_by: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function readyState(items: FixedAsset[]): FixedAssetListState {
  return { status: "ready", items, perPage: 25, hasMore: false };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(ALL_PERMISSIONS);
  state.list = readyState([buildAsset()]);
});

describe("FixedAssetsView", () => {
  it("renders assets from the loaded list", () => {
    state.list = readyState([
      buildAsset({ id: 7, asset_number: "FA-000007" }),
      buildAsset({ id: 8, asset_number: "FA-000008", status: "ACTIVE" }),
    ]);

    render(<FixedAssetsView />);

    expect(screen.getByRole("heading", { name: "Fixed assets" })).toBeInTheDocument();
    expect(screen.getAllByText("FA-000007").length).toBeGreaterThan(0);
    expect(screen.getAllByText("FA-000008").length).toBeGreaterThan(0);
  });

  it("offers Edit for a draft", () => {
    render(<FixedAssetsView />);

    expect(screen.getAllByRole("link", { name: "Edit" }).length).toBeGreaterThan(0);
  });

  it("does not offer Edit once an asset is capitalised", () => {
    state.list = readyState([buildAsset({ id: 8, status: "ACTIVE" })]);

    render(<FixedAssetsView />);

    expect(screen.queryAllByRole("link", { name: "Edit" })).toHaveLength(0);
  });

  it("shows the empty state when there are no assets", () => {
    state.list = readyState([]);

    render(<FixedAssetsView />);

    expect(screen.getByText("No fixed assets yet")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<FixedAssetsView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the create action without the create permission", () => {
    state.perms = new Set(["accounting.fixed_asset.view"]);

    render(<FixedAssetsView />);

    expect(screen.queryByRole("button", { name: "New fixed asset" })).not.toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.list = {
      status: "error",
      items: [],
      perPage: 25,
      hasMore: false,
      message: "Server exploded.",
    };

    render(<FixedAssetsView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("presents the register variant without a status filter", async () => {
    const user = userEvent.setup();

    render(<FixedAssetsView mode="register" />);

    expect(screen.getByRole("heading", { name: "Fixed asset register" })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "More filters" }));

    expect(screen.queryByLabelText("Status")).not.toBeInTheDocument();
  });
});
