import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { AccountingPeriod, ClosingCheck, PeriodListState } from "@/lib/periods/types";

import { PeriodsView } from "./periods-view";

const ALL_PERMISSIONS = [
  "accounting.periods.view",
  "accounting.periods.create",
  "accounting.periods.update",
  "accounting.periods.close",
  "accounting.periods.reopen",
];

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    list: null as unknown,
    years: { status: "ready", items: [] } as unknown,
    closingCheck: null as unknown,
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

vi.mock("@/lib/periods/use-periods", () => ({
  PERIOD_LIST_PER_PAGE: 50,
  usePeriodList: () => state.list,
}));

vi.mock("@/lib/periods/use-closing-check", () => ({
  useClosingCheck: () => ({ status: "ready", data: state.closingCheck }),
}));

vi.mock("@/lib/financial-years/use-financial-years", () => ({
  useFinancialYearOptions: () => state.years,
}));

vi.mock("@/lib/periods/api", () => ({
  createAccountingPeriod: vi.fn(),
  updateAccountingPeriod: vi.fn(),
  closeAccountingPeriod: vi.fn(),
  reopenAccountingPeriod: vi.fn(),
  fetchPeriodClosingCheck: vi.fn(),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

function buildPeriod(overrides: Partial<AccountingPeriod> = {}): AccountingPeriod {
  return {
    id: 1,
    name: "April 2026",
    start_date: "2026-04-01",
    end_date: "2026-04-30",
    status: "OPEN",
    accepts_postings: true,
    financial_year_id: 4,
    financial_year: { id: 4, name: "2026-2027", status: "OPEN" },
    closed_by: null,
    closed_at: null,
    reopened_by: null,
    reopened_at: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function readyState(items: AccountingPeriod[]): PeriodListState {
  return { status: "ready", items, perPage: 50, hasMore: false };
}

function buildClosingCheck(overrides: Partial<ClosingCheck> = {}): ClosingCheck {
  return {
    period: {
      id: 1,
      name: "April 2026",
      start_date: "2026-04-01",
      end_date: "2026-04-30",
      status: "OPEN",
      financial_year_id: 4,
    },
    eligible: true,
    already_closed: false,
    summary: { PASS: 3, WARNING: 0, FAIL: 0 },
    blocking_findings: [],
    warning_findings: [],
    findings: [],
    required_action: null,
    ...overrides,
  };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(ALL_PERMISSIONS);
  state.list = readyState([buildPeriod()]);
  state.years = { status: "ready", items: [] };
  state.closingCheck = buildClosingCheck();
});

describe("PeriodsView", () => {
  it("renders accounting periods from the loaded list", () => {
    state.list = readyState([
      buildPeriod({ id: 1, name: "April 2026" }),
      buildPeriod({ id: 2, name: "May 2026" }),
    ]);

    render(<PeriodsView />);

    expect(screen.getByRole("heading", { name: "Accounting periods" })).toBeInTheDocument();
    expect(screen.getAllByText("April 2026").length).toBeGreaterThan(0);
    expect(screen.getAllByText("May 2026").length).toBeGreaterThan(0);
  });

  it("shows the empty state when there are no periods", () => {
    state.list = readyState([]);

    render(<PeriodsView />);

    expect(screen.getByText("No accounting periods yet")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<PeriodsView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the create button without the create permission", () => {
    state.perms = new Set(["accounting.periods.view"]);

    render(<PeriodsView />);

    expect(screen.queryByRole("button", { name: "New period" })).not.toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.list = {
      status: "error",
      items: [],
      perPage: 50,
      hasMore: false,
      message: "Server exploded.",
    };

    render(<PeriodsView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("opens the create dialog from the header action", async () => {
    const user = userEvent.setup();

    render(<PeriodsView />);

    await user.click(screen.getByRole("button", { name: "New period" }));

    expect(screen.getByRole("dialog", { name: "New accounting period" })).toBeInTheDocument();
  });

  it("opens the closing review and shows a ready verdict", async () => {
    const user = userEvent.setup();

    render(<PeriodsView />);

    await user.click(screen.getAllByRole("button", { name: "Review" })[0]);

    expect(screen.getByRole("dialog", { name: "Closing review: April 2026" })).toBeInTheDocument();
    expect(screen.getByText("Ready to close")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Close period" })).toBeInTheDocument();
  });

  it("does not offer to close when the review is not eligible", async () => {
    const user = userEvent.setup();
    state.closingCheck = buildClosingCheck({
      eligible: false,
      summary: { PASS: 1, WARNING: 0, FAIL: 1 },
      blocking_findings: [
        {
          control_code: "UNBALANCED_JOURNAL",
          status: "FAIL",
          description: "A posted journal is unbalanced.",
          resource_type: "journal",
          resource_id: 12,
          financial_date: "2026-04-15",
          details: [],
        },
      ],
      required_action: "Resolve the 1 blocking control finding(s) before closing this period.",
    });

    render(<PeriodsView />);

    await user.click(screen.getAllByRole("button", { name: "Review" })[0]);

    expect(screen.getByText("Not ready to close")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Close period" })).not.toBeInTheDocument();
  });

  it("offers reopen for a closed period only with the reopen permission", async () => {
    state.list = readyState([
      buildPeriod({ id: 2, name: "March 2026", status: "CLOSED", accepts_postings: false }),
    ]);
    state.perms = new Set(["accounting.periods.view"]);
    const user = userEvent.setup();

    const { unmount } = render(<PeriodsView />);
    expect(screen.queryByRole("button", { name: "Reopen" })).not.toBeInTheDocument();
    unmount();

    state.perms = new Set(["accounting.periods.view", "accounting.periods.reopen"]);
    render(<PeriodsView />);
    await user.click(screen.getAllByRole("button", { name: "Reopen" })[0]);

    expect(screen.getByRole("dialog", { name: "Reopen March 2026" })).toBeInTheDocument();
  });
});
