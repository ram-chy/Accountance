import { ApiError } from "@/lib/api/errors";
import { jsonFailure, jsonSuccess } from "@/lib/api/response";
import { validateResetTokenRequest } from "@/lib/auth/laravel";

/**
 * `GET /api/session/reset-password/{token}?email=`
 *
 * Public. Reports whether the emailed reset token is still usable so the reset
 * screen can show either the password form or an "expired link" state. The
 * backend returns the same 400 for an unknown address and a stale token, so
 * this route never distinguishes them.
 */

type ResetTokenRouteContext = {
  params: Promise<{ token: string }>;
};

export async function GET(
  request: Request,
  context: ResetTokenRouteContext,
): Promise<Response> {
  const { token } = await context.params;

  if (!/^[A-Za-z0-9]+$/.test(token)) {
    return jsonFailure("This password reset link is invalid or has expired.", 400);
  }

  const email = (new URL(request.url).searchParams.get("email") ?? "")
    .trim()
    .toLowerCase();

  if (!email) {
    return jsonFailure("This password reset link is invalid or has expired.", 400);
  }

  try {
    const data = await validateResetTokenRequest(token, email);

    return jsonSuccess(data, "This reset link is valid.");
  } catch (error) {
    if (error instanceof ApiError && error.status > 0) {
      return jsonFailure(error.message, error.status);
    }

    return jsonFailure(
      "We could not verify the reset link right now. Please try again.",
      502,
    );
  }
}
