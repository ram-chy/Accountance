import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";

import { ForgotPasswordForm } from "./forgot-password-form";

const { client } = vi.hoisted(() => ({
  client: { requestPasswordReset: vi.fn() },
}));

vi.mock("@/lib/auth/client", () => client);

vi.mock("@/components/theme/theme-toggle", () => ({ ThemeToggle: () => null }));

vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

beforeEach(() => {
  client.requestPasswordReset.mockReset().mockResolvedValue(undefined);
});

describe("ForgotPasswordForm", () => {
  it("shows the same generic confirmation regardless of the account", async () => {
    const user = userEvent.setup();

    render(<ForgotPasswordForm />);

    await user.type(screen.getByLabelText(/email/i), "someone@example.test");
    await user.click(screen.getByRole("button", { name: "Send reset link" }));

    expect(client.requestPasswordReset).toHaveBeenCalledWith("someone@example.test");
    expect(
      await screen.findByText(/password recovery instructions have been sent/i),
    ).toBeInTheDocument();
  });

  it("surfaces a validation error for a malformed email", async () => {
    const user = userEvent.setup();
    client.requestPasswordReset.mockRejectedValue(
      new ApiError({
        status: 422,
        message: "The given data was invalid.",
        errors: { email: ["The email field must be a valid email address."] },
      }),
    );

    render(<ForgotPasswordForm />);

    await user.type(screen.getByLabelText(/email/i), "not-an-email");
    await user.click(screen.getByRole("button", { name: "Send reset link" }));

    expect(await screen.findByText("Please enter a valid email address.")).toBeInTheDocument();
  });
});
