import { ApiError } from "@/lib/api/errors";
import { jsonFailure, jsonSuccess } from "@/lib/api/response";
import { isSameOriginRequest } from "@/lib/auth/http";
import { sendEmailVerificationRequest } from "@/lib/auth/laravel";
import { clearSession, getSession } from "@/lib/auth/session";

/**
 * `POST /api/session/verify-email`
 *
 * Authenticated. Asks Laravel to resend the email-verification link for the
 * signed-in user. The endpoint is idempotent server-side (an already-verified
 * address returns a success message), so no local state is kept.
 */

export async function POST(request: Request): Promise<Response> {
  if (!isSameOriginRequest(request)) {
    return jsonFailure("The request was rejected.", 403);
  }

  const session = await getSession();

  if (!session) {
    return jsonFailure("Unauthenticated.", 401);
  }

  try {
    await sendEmailVerificationRequest(session.token);
  } catch (error) {
    if (error instanceof ApiError) {
      if (error.status === 401) {
        await clearSession();
      }

      return jsonFailure(error.message, error.status > 0 ? error.status : 502);
    }

    return jsonFailure(
      "We could not send a verification email right now. Please try again.",
      502,
    );
  }

  return jsonSuccess(
    undefined,
    "If your address is unverified, a fresh verification link has been sent.",
  );
}
