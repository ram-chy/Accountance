import { ApiError } from "@/lib/api/errors";
import { jsonFailure, jsonSuccess } from "@/lib/api/response";
import { isSameOriginRequest, readJsonBody } from "@/lib/auth/http";
import { resetPasswordRequest } from "@/lib/auth/laravel";

/**
 * `POST /api/session/reset-password`
 *
 * Public. Consumes a single-use reset token with a new password. The token and
 * address are validated by Laravel `Password::reset`; the backend answers 400
 * with the broker's message when the token is stale, which the form surfaces
 * as an expired-link state.
 *
 * No session is established here: after a reset the user signs in normally, so
 * a stolen reset link can never become a live session.
 */

export async function POST(request: Request): Promise<Response> {
  if (!isSameOriginRequest(request)) {
    return jsonFailure("The request was rejected.", 403);
  }

  const body = await readJsonBody(request);

  const token = typeof body?.token === "string" ? body.token.trim() : "";
  const email =
    typeof body?.email === "string" ? body.email.trim().toLowerCase() : "";
  const password = typeof body?.password === "string" ? body.password : "";
  const passwordConfirmation =
    typeof body?.password_confirmation === "string"
      ? body.password_confirmation
      : "";

  const errors: Record<string, string[]> = {};

  if (!token) errors.token = ["The reset token is missing."];
  if (!email) errors.email = ["The email field is required."];
  if (!password) errors.password = ["The password field is required."];
  if (password && password !== passwordConfirmation) {
    errors.password_confirmation = ["The password confirmation does not match."];
  }

  if (Object.keys(errors).length > 0) {
    return jsonFailure("The given data was invalid.", 422, errors);
  }

  try {
    await resetPasswordRequest({
      token,
      email,
      password,
      password_confirmation: passwordConfirmation,
    });
  } catch (error) {
    if (error instanceof ApiError) {
      return jsonFailure(error.message, error.status > 0 ? error.status : 502, error.errors);
    }

    return jsonFailure("We could not reset your password. Please try again.", 502);
  }

  return jsonSuccess(
    undefined,
    "Password reset successfully. Sign in with your new password.",
  );
}
