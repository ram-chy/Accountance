import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";

import { ChangePasswordForm } from "./change-password-form";

const { client } = vi.hoisted(() => ({
  client: { changePassword: vi.fn() },
}));

vi.mock("@/lib/auth/client", () => client);

vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

beforeEach(() => {
  client.changePassword.mockReset().mockResolvedValue(undefined);
});

describe("ChangePasswordForm", () => {
  it("blocks submission when the confirmation does not match", async () => {
    const user = userEvent.setup();

    render(<ChangePasswordForm />);

    await user.type(screen.getByLabelText(/current password/i), "OldPass!1");
    await user.type(screen.getByLabelText(/^new password/i), "Str0ng!pass");
    await user.type(screen.getByLabelText(/confirm new password/i), "Str0ng!passx");
    await user.click(screen.getByRole("button", { name: "Change password" }));

    expect(client.changePassword).not.toHaveBeenCalled();
    expect(
      screen.getByText("The password confirmation does not match."),
    ).toBeInTheDocument();
  });

  it("blocks submission when the new password is weak", async () => {
    const user = userEvent.setup();

    render(<ChangePasswordForm />);

    await user.type(screen.getByLabelText(/current password/i), "OldPass!1");
    await user.type(screen.getByLabelText(/^new password/i), "weak");
    await user.type(screen.getByLabelText(/confirm new password/i), "weak");
    await user.click(screen.getByRole("button", { name: "Change password" }));

    expect(client.changePassword).not.toHaveBeenCalled();
  });

  it("submits a valid change and clears the fields", async () => {
    const user = userEvent.setup();

    render(<ChangePasswordForm />);

    const current = screen.getByLabelText(/current password/i);
    await user.type(current, "OldPass!1");
    await user.type(screen.getByLabelText(/^new password/i), "Str0ng!pass");
    await user.type(screen.getByLabelText(/confirm new password/i), "Str0ng!pass");
    await user.click(screen.getByRole("button", { name: "Change password" }));

    await waitFor(() =>
      expect(client.changePassword).toHaveBeenCalledWith({
        current_password: "OldPass!1",
        new_password: "Str0ng!pass",
        new_password_confirmation: "Str0ng!pass",
      }),
    );

    expect(current).toHaveValue("");
  });

  it("surfaces a server validation error on the field", async () => {
    const user = userEvent.setup();
    client.changePassword.mockRejectedValue(
      new ApiError({
        status: 422,
        message: "The given data was invalid.",
        errors: { current_password: ["The current password is incorrect."] },
      }),
    );

    render(<ChangePasswordForm />);

    await user.type(screen.getByLabelText(/current password/i), "WrongPass!1");
    await user.type(screen.getByLabelText(/^new password/i), "Str0ng!pass");
    await user.type(screen.getByLabelText(/confirm new password/i), "Str0ng!pass");
    await user.click(screen.getByRole("button", { name: "Change password" }));

    expect(
      await screen.findByText("The current password is incorrect."),
    ).toBeInTheDocument();
  });
});
