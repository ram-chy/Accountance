import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { DepreciationReport } from "@/lib/fixed-assets/types";

import { DepreciationReportView } from "./depreciation-report-view";

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    canView: true,
    report: null as unknown,
  },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({
    activeCompany: state.hasCompany ? { id: 1, name: "Acme Ltd" } : null,
  }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({
    can: (name: string) => (name === "accounting.fixed_asset.view" ? state.canView : false),
  }),
}));

vi.mock("@/lib/fixed-assets/use-depreciation-report", () => ({
  useDepreciationReport: () => state.report,
}));

vi.mock("@/lib/fixed-asset-categories/use-fixed-asset-category-options", () => ({
  useFixedAssetCategoryOptions: () => ({ status: "ready", items: [] }),
}));

const report: DepreciationReport = {
  from: "2026-01-01",
  to: "2026-01-31",
  total_depreciation: "450.0000",
  assets: [
    {
      fixed_asset_id: 7,
      asset_number: "FA-000007",
      name: "Delivery van",
      category: "Motor vehicles",
      period_count: 1,
      total_depreciation: "450.0000",
      entries: [
        {
          id: 1,
          fixed_asset_id: 7,
          period_number: 1,
          period_start_date: "2026-01-01",
          period_end_date: "2026-01-31",
          amount: "450.0000",
          is_final_period: false,
          journal_id: 5,
          journal: null,
          posted_at: "2026-01-31T10:00:00Z",
          posted_by: 1,
          created_at: null,
        },
      ],
    },
  ],
};

beforeEach(() => {
  state.hasCompany = true;
  state.canView = true;
  state.report = { status: "ready", data: report };
});

describe("DepreciationReportView", () => {
  it("renders the server total and the per-asset group", () => {
    render(<DepreciationReportView />);

    expect(screen.getByRole("heading", { name: "Depreciation report" })).toBeInTheDocument();
    // The header total and the group subtotal both show the same figure.
    expect(screen.getAllByText("450.00").length).toBeGreaterThan(0);
    expect(screen.getByRole("link", { name: "FA-000007" })).toBeInTheDocument();
  });

  it("shows the no-depreciation state for an empty report", () => {
    state.report = { status: "ready", data: { ...report, assets: [], total_depreciation: "0.0000" } };

    render(<DepreciationReportView />);

    expect(screen.getByText("No depreciation posted")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<DepreciationReportView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the report without the view permission", () => {
    state.canView = false;

    render(<DepreciationReportView />);

    expect(screen.getByText("Reports unavailable")).toBeInTheDocument();
  });
});
