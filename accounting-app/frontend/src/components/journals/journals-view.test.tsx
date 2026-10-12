import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Journal, JournalListState } from "@/lib/journals/types";

import { JournalsView } from "./journals-view";

const ALL_PERMISSIONS = [
  "journals.view",
  "journals.create",
  "journals.update",
  "journals.delete",
  "journals.post",
];

const { state } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    list: null as unknown,
    accounts: { status: "ready", items: [{ id: 10, code: "1000", name: "Cash", is_active: true }] },
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

vi.mock("@/lib/journals/use-journal-list", () => ({
  JOURNAL_LIST_PER_PAGE: 25,
  useJournalList: () => state.list,
}));

vi.mock("@/lib/accounts/use-account-options", () => ({
  useAccountOptions: () => state.accounts,
}));

vi.mock("@/lib/journals/api", () => ({
  createJournal: vi.fn(),
  updateJournal: vi.fn(),
  deleteJournal: vi.fn(),
  postJournal: vi.fn(),
}));

function buildJournal(overrides: Partial<Journal> = {}): Journal {
  return {
    id: 1,
    journal_number: "JNL-000001",
    journal_date: "2027-01-15",
    description: "Opening entry",
    reference: null,
    status: "DRAFT",
    source_type: "MANUAL",
    source_id: null,
    created_by: 1,
    posted_by: null,
    posted_at: null,
    created_at: null,
    updated_at: null,
    lines: [
      {
        id: 1,
        line_number: 1,
        account_id: 10,
        account_code: "1000",
        account_name: "Cash",
        description: null,
        debit: "100.0000",
        credit: "0.0000",
        currency_id: null,
        currency_code: null,
        exchange_rate: null,
        foreign_debit: null,
        foreign_credit: null,
      },
    ],
    totals: { total_debit: "100.0000", total_credit: "100.0000", is_balanced: true },
    ...overrides,
  };
}

function readyState(items: Journal[]): JournalListState {
  return { status: "ready", items, perPage: 25, hasMore: false };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(ALL_PERMISSIONS);
  state.list = readyState([buildJournal()]);
});

describe("JournalsView", () => {
  it("renders journals from the loaded list", () => {
    state.list = readyState([
      buildJournal({ id: 1, journal_number: "JNL-000001", description: "Opening entry" }),
      buildJournal({ id: 2, journal_number: "JNL-000002", description: "Cash sale" }),
    ]);

    render(<JournalsView />);

    expect(screen.getByRole("heading", { name: "Journals" })).toBeInTheDocument();
    expect(screen.getAllByText("JNL-000001").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Opening entry").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Cash sale").length).toBeGreaterThan(0);
  });

  it("shows the empty state when there are no journals", () => {
    state.list = readyState([]);

    render(<JournalsView />);

    expect(screen.getByText("No journals yet")).toBeInTheDocument();
  });

  it("prompts for a company when none is selected", () => {
    state.hasCompany = false;

    render(<JournalsView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the create button without the create permission", () => {
    state.perms = new Set(["journals.view"]);

    render(<JournalsView />);

    expect(screen.queryByRole("button", { name: "New journal" })).not.toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.list = {
      status: "error",
      items: [],
      perPage: 25,
      hasMore: false,
      message: "Server exploded.",
    };

    render(<JournalsView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("opens the create dialog from the header action", async () => {
    const user = userEvent.setup();

    render(<JournalsView />);

    await user.click(screen.getByRole("button", { name: "New journal" }));

    expect(screen.getByRole("dialog", { name: "New journal" })).toBeInTheDocument();
  });

  it("opens the post confirmation for a draft row", async () => {
    const user = userEvent.setup();

    render(<JournalsView />);

    await user.click(screen.getAllByRole("button", { name: "Post" })[0]);

    expect(screen.getByRole("dialog", { name: "Post journal" })).toBeInTheDocument();
    expect(screen.getByText(/Posting is permanent/)).toBeInTheDocument();
  });
});
