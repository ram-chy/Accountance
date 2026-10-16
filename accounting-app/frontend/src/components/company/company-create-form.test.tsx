import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CompanyCreateForm } from "./company-create-form";

const { state, api, nav } = vi.hoisted(() => ({
  state: {
    perms: new Set<string>([]),
    switchCompany: vi.fn().mockResolvedValue(undefined),
  },
  api: { createCompany: vi.fn() },
  nav: { push: vi.fn(), refresh: vi.fn() },
}));

vi.mock("@/components/auth/auth-provider", () => ({
  useAuth: () => ({ switchCompany: state.switchCompany }),
}));

vi.mock("@/components/auth/use-permissions", () => ({
  usePermissions: () => ({ can: (name: string) => state.perms.has(name) }),
}));

vi.mock("@/lib/company/api", () => api);

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: nav.push, refresh: nav.refresh }),
}));

vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const createdCompany = {
  id: 99,
  name: "New Co",
  address: {},
  timezone: "UTC",
  date_format: "Y-m-d",
};

beforeEach(() => {
  state.perms = new Set(["companies.create"]);
  state.switchCompany.mockClear();
  api.createCompany.mockReset().mockResolvedValue(createdCompany);
  nav.push.mockClear();
  nav.refresh.mockClear();
});

describe("CompanyCreateForm", () => {
  it("is unavailable without the create permission", () => {
    state.perms = new Set<string>();

    render(<CompanyCreateForm />);

    expect(screen.getByText("Not available")).toBeInTheDocument();
    expect(screen.queryByLabelText(/company name/i)).not.toBeInTheDocument();
  });

  it("renders the profile fields with the create permission", () => {
    render(<CompanyCreateForm />);

    expect(screen.getByLabelText(/company name/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Create company" })).toBeInTheDocument();
  });

  it("creates the company, switches to it and navigates", async () => {
    const user = userEvent.setup();

    render(<CompanyCreateForm />);

    await user.type(screen.getByLabelText(/company name/i), "New Co");
    await user.click(screen.getByRole("button", { name: "Create company" }));

    await waitFor(() => expect(api.createCompany).toHaveBeenCalledTimes(1));

    const payload = api.createCompany.mock.calls[0][0] as Record<string, unknown>;
    expect(payload.name).toBe("New Co");
    expect(payload.is_default).toBe(true);

    await waitFor(() => expect(state.switchCompany).toHaveBeenCalledWith(99));
    expect(nav.push).toHaveBeenCalledWith("/settings/company");
  });

  it("does not switch companies when the toggle is off", async () => {
    const user = userEvent.setup();

    render(<CompanyCreateForm />);

    await user.type(screen.getByLabelText(/company name/i), "New Co");
    await user.click(screen.getByRole("checkbox"));
    await user.click(screen.getByRole("button", { name: "Create company" }));

    await waitFor(() => expect(api.createCompany).toHaveBeenCalledTimes(1));

    const payload = api.createCompany.mock.calls[0][0] as Record<string, unknown>;
    expect(payload.is_default).toBe(false);
    expect(state.switchCompany).not.toHaveBeenCalled();
  });
});
