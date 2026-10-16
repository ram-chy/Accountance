import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { User, UserListState } from "@/lib/users/types";

import { CompanyMembersView } from "./company-members-view";

const { state, api } = vi.hoisted(() => ({
  state: {
    hasCompany: true,
    perms: new Set<string>([]),
    list: null as unknown,
  },
  api: {
    addCompanyMember: vi.fn(),
    removeCompanyMember: vi.fn(),
  },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({
    activeCompany: state.hasCompany ? { id: 5, name: "Acme Ltd" } : null,
  }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({
    can: (name: string) => state.perms.has(name),
  }),
}));

vi.mock("@/lib/users/use-user-list", () => ({
  useUserList: () => state.list,
}));

vi.mock("@/lib/company/api", () => api);

vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function buildUser(overrides: Partial<User> = {}): User {
  return {
    id: 7,
    first_name: "Grace",
    last_name: "Hopper",
    full_name: "Grace Hopper",
    email: "grace@example.test",
    mobile_no: null,
    email_verified: true,
    is_active: true,
    roles: [{ id: 1, name: "Manager" }],
    last_login_at: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function ready(items: User[]): UserListState {
  return { status: "ready", items, hasMore: false };
}

beforeEach(() => {
  state.hasCompany = true;
  state.perms = new Set(["companies.update", "users.view"]);
  state.list = ready([buildUser()]);
  api.addCompanyMember.mockReset().mockResolvedValue({});
  api.removeCompanyMember.mockReset().mockResolvedValue({});
});

describe("CompanyMembersView", () => {
  it("prompts for a company when none is active", () => {
    state.hasCompany = false;

    render(<CompanyMembersView />);

    expect(screen.getByText("No company selected")).toBeInTheDocument();
  });

  it("hides the workflow without the company update permission", () => {
    state.perms = new Set<string>();

    render(<CompanyMembersView />);

    expect(screen.getByText("Members unavailable")).toBeInTheDocument();
  });

  it("states that the API exposes no member list", () => {
    render(<CompanyMembersView />);

    expect(screen.getByText(/does not provide a company member list/i)).toBeInTheDocument();
  });

  it("adds a member by user id", async () => {
    const user = userEvent.setup();

    render(<CompanyMembersView />);

    await user.selectOptions(screen.getByLabelText("User", { selector: "#add-member-user" }), "7");
    await user.click(screen.getByRole("button", { name: "Add member" }));

    await waitFor(() =>
      expect(api.addCompanyMember).toHaveBeenCalledWith(5, 7),
    );
  });

  it("removes a member only after confirmation", async () => {
    const user = userEvent.setup();

    render(<CompanyMembersView />);

    await user.selectOptions(
      screen.getByLabelText("User", { selector: "#remove-member-user" }),
      "7",
    );
    await user.click(screen.getByRole("button", { name: "Remove member" }));

    expect(api.removeCompanyMember).not.toHaveBeenCalled();
    expect(screen.getByRole("dialog", { name: "Remove member" })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Remove" }));

    await waitFor(() =>
      expect(api.removeCompanyMember).toHaveBeenCalledWith(5, 7),
    );
  });
});
