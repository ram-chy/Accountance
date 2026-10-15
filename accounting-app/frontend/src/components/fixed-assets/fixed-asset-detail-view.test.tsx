import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { FixedAsset, FixedAssetDetailState } from "@/lib/fixed-assets/types";

import { FixedAssetDetailView } from "./fixed-asset-detail-view";

const ALL_PERMISSIONS = [
  "accounting.fixed_asset.view",
  "accounting.fixed_asset.update",
  "accounting.fixed_asset.delete",
  "accounting.fixed_asset.capitalize",
  "accounting.fixed_asset.depreciate",
  "accounting.fixed_asset.dispose",
];

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    detail: null as unknown,
  },
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
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

vi.mock("@/lib/fixed-assets/use-fixed-asset", () => ({
  useFixedAsset: () => state.detail,
}));

vi.mock("@/lib/fixed-assets/use-fixed-asset-schedule", () => ({
  useFixedAssetSchedule: () => ({
    status: "ready",
    data: { fixed_asset: null, upcoming_periods: [] },
  }),
}));

vi.mock("@/lib/fixed-assets/api", () => ({
  capitalizeFixedAsset: vi.fn(),
  deleteFixedAsset: vi.fn(),
  depreciateFixedAsset: vi.fn(),
  disposeFixedAsset: vi.fn(),
}));

vi.mock("@/lib/accounts/use-account-options", () => ({
  useAccountOptions: () => ({ status: "ready", items: [] }),
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

function ready(asset: FixedAsset): FixedAssetDetailState {
  return { status: "ready", asset };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(ALL_PERMISSIONS);
  state.detail = ready(buildAsset());
});

describe("FixedAssetDetailView", () => {
  it("renders the asset identity and book values", () => {
    render(<FixedAssetDetailView assetId={7} />);

    expect(screen.getByRole("heading", { name: "FA-000007" })).toBeInTheDocument();
    expect(screen.getByText("Delivery van")).toBeInTheDocument();
    expect(screen.getByText("Draft")).toBeInTheDocument();
    // Cost and net book value are equal for a fresh asset, so both appear.
    expect(screen.getAllByText("30,000.00").length).toBeGreaterThan(0);
  });

  it("offers draft-only actions for a draft", () => {
    render(<FixedAssetDetailView assetId={7} />);

    expect(screen.getByRole("button", { name: "Capitalise" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Delete" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Edit" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Post depreciation" })).not.toBeInTheDocument();
  });

  it("offers depreciation and disposal for an active asset", () => {
    state.detail = ready(buildAsset({ status: "ACTIVE" }));

    render(<FixedAssetDetailView assetId={7} />);

    expect(screen.getByRole("button", { name: "Post depreciation" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Dispose" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Capitalise" })).not.toBeInTheDocument();
  });

  it("hides lifecycle actions the role cannot perform", () => {
    state.perms = new Set(["accounting.fixed_asset.view"]);

    render(<FixedAssetDetailView assetId={7} />);

    expect(screen.queryByRole("button", { name: "Capitalise" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Delete" })).not.toBeInTheDocument();
  });

  it("shows a not-found state", () => {
    state.detail = { status: "notfound", asset: null };

    render(<FixedAssetDetailView assetId={999} />);

    expect(screen.getByText("Asset not found")).toBeInTheDocument();
  });

  it("opens the capitalise confirmation", async () => {
    const user = userEvent.setup();

    render(<FixedAssetDetailView assetId={7} />);

    await user.click(screen.getByRole("button", { name: "Capitalise" }));

    expect(screen.getByRole("dialog", { name: "Capitalise asset" })).toBeInTheDocument();
  });

  it("opens the disposal form for a fully depreciated asset", async () => {
    const user = userEvent.setup();
    state.detail = ready(buildAsset({ status: "FULLY_DEPRECIATED" }));

    render(<FixedAssetDetailView assetId={7} />);

    await user.click(screen.getByRole("button", { name: "Dispose" }));

    expect(screen.getByRole("dialog", { name: "Dispose of asset" })).toBeInTheDocument();
  });
});
