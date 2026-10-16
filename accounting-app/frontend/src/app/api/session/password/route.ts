import { ApiError } from "@/lib/api/errors";
import { jsonFailure, jsonSuccess } from "@/lib/api/response";
import { isSameOriginRequest, readJsonBody } from "@/lib/auth/http";
import { changePasswordRequest } from "@/lib/auth/laravel";
import { clearSession, getSession, writeSession } from "@/lib/auth/session";

/**
 * `PUT /api/session/password`
 *
 * Authenticated. Changes the password for the user behind the session cookie.
 *
 * The backend blacklists the token used for the request and returns a
 * replacement; the new token is sealed back into the httpOnly cookie (keeping
 * the active company) so the caller stays signed in with a token that carries
 * the new password-version claim. The old token can no longer be replayed.
 */

export async function PUT(request: Request): Promise<Response> {
  if (!isSameOriginRequest(request)) {
    return jsonFailure("The request was rejected.", 403);
  }

  const session = await getSession();

  if (!session) {
    return jsonFailure("Unauthenticated.", 401);
  }

  const body = await readJsonBody(request);

  const currentPassword =
    typeof body?.current_password === "string" ? body.current_password : "";
  const newPassword =
    typeof body?.new_password === "string" ? body.new_password : "";
  const newPasswordConfirmation =
    typeof body?.new_password_confirmation === "string"
      ? body.new_password_confirmation
      : "";

  const errors: Record<string, string[]> = {};

  if (!currentPassword) {
    errors.current_password = ["The current password field is required."];
  }
  if (!newPassword) {
    errors.new_password = ["The new password field is required."];
  }
  if (newPassword && newPassword !== newPasswordConfirmation) {
    errors.new_password_confirmation = [
      "The password confirmation does not match.",
    ];
  }

  if (Object.keys(errors).length > 0) {
    return jsonFailure("The given data was invalid.", 422, errors);
  }

  let result;

  try {
    result = await changePasswordRequest(session.token, {
      current_password: currentPassword,
      new_password: newPassword,
      new_password_confirmation: newPasswordConfirmation,
    });
  } catch (error) {
    if (error instanceof ApiError) {
      if (error.status === 401) {
        await clearSession();
      }

      return jsonFailure(error.message, error.status > 0 ? error.status : 502, error.errors);
    }

    return jsonFailure(
      "We could not change your password right now. Please try again.",
      502,
    );
  }

  const seconds = Number.isFinite(result.expires_in) ? result.expires_in : 3600;

  await writeSession({
    token: result.token,
    companyId: session.companyId,
    expiresAt: Date.now() + seconds * 1000,
  });

  return jsonSuccess({ changed: true }, "Password changed successfully.");
}
