import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { User, UserListState } from "@/lib/users/types";

import { UsersView } from "./users-view";

const { state } = vi.hoisted(() => ({
  state: {
    currentUserId: 1 as number | null,
    perms: new Set<string>([]),
    list: null as unknown,
  },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({ user: { id: state.currentUserId, full_name: "Ada Lovelace" } }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({
    can: (name: string) => state.perms.has(name),
  }),
}));

vi.mock("@/lib/users/use-user-list", () => ({
  useUserList: () => state.list,
}));

vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function buildUser(overrides: Partial<User> = {}): User {
  return {
    id: 2,
    first_name: "Grace",
    last_name: "Hopper",
    full_name: "Grace Hopper",
    email: "grace@example.test",
    mobile_no: null,
    email_verified: true,
    is_active: true,
    roles: [{ id: 1, name: "Admin" }],
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
  state.currentUserId = 1;
  state.perms = new Set(["users.view"]);
  state.list = ready([buildUser()]);
});

describe("UsersView", () => {
  it("renders the users table with names and roles", () => {
    state.list = ready([buildUser({ id: 3, full_name: "Grace Hopper" })]);

    render(<UsersView />);

    expect(screen.getByRole("heading", { name: "Users" })).toBeInTheDocument();
    expect(screen.getByText("Grace Hopper")).toBeInTheDocument();
    expect(screen.getAllByText("Admin").length).toBeGreaterThan(0);
  });

  it("shows an empty state when there are no users", () => {
    state.list = ready([]);

    render(<UsersView />);

    expect(screen.getByText("No users found")).toBeInTheDocument();
  });

  it("hides the list without the view permission", () => {
    state.perms = new Set<string>();

    render(<UsersView />);

    expect(screen.getByText("Users unavailable")).toBeInTheDocument();
  });

  it("renders the server error with a retry action", () => {
    state.list = { status: "error", items: [], hasMore: false, message: "Server exploded." };

    render(<UsersView />);

    expect(screen.getByText("Server exploded.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("shows the create action only with the create permission", () => {
    state.perms = new Set(["users.view", "users.create"]);

    render(<UsersView />);

    expect(screen.getByRole("button", { name: "New user" })).toBeInTheDocument();
  });

  it("hides the create action without the create permission", () => {
    render(<UsersView />);

    expect(screen.queryByRole("button", { name: "New user" })).not.toBeInTheDocument();
  });

  it("offers deactivate only with the delete permission and never for self", () => {
    state.perms = new Set(["users.view", "users.delete"]);
    state.list = ready([
      buildUser({ id: 3, full_name: "Grace Hopper" }),
      buildUser({ id: 1, full_name: "Ada Lovelace" }),
    ]);

    render(<UsersView />);

    const deactivateButtons = screen.getAllByRole("button", { name: "Deactivate" });
    expect(deactivateButtons).toHaveLength(1);
  });

  it("notes when more users may exist beyond the page", () => {
    state.list = { status: "ready", items: [buildUser()], hasMore: true };

    render(<UsersView />);

    expect(screen.getByText(/more may exist/i)).toBeInTheDocument();
  });
});
