import { ApiError } from "@/lib/api/errors";
import { jsonFailure, jsonSuccess } from "@/lib/api/response";
import { isSameOriginRequest, readJsonBody } from "@/lib/auth/http";
import { forgotPasswordRequest } from "@/lib/auth/laravel";

/**
 * `POST /api/session/forgot-password`
 *
 * Public. Asks Laravel to email a reset link and returns a fixed, generic
 * message regardless of whether the address exists. The backend already
 * discards the broker's distinct outcomes; this route does not reintroduce the
 * distinction. A 422 is only ever a malformed address, never "not found".
 */

const GENERIC_MESSAGE =
  "If the account exists, password recovery instructions have been sent.";

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export async function POST(request: Request): Promise<Response> {
  if (!isSameOriginRequest(request)) {
    return jsonFailure("The request was rejected.", 403);
  }

  const body = await readJsonBody(request);
  const email = typeof body?.email === "string" ? body.email.trim().toLowerCase() : "";

  if (!email || !EMAIL_PATTERN.test(email)) {
    return jsonFailure("The given data was invalid.", 422, {
      email: ["The email field must be a valid email address."],
    });
  }

  try {
    await forgotPasswordRequest(email);
  } catch (error) {
    if (error instanceof ApiError && error.isValidation) {
      // Format-level validation only; the account's existence is never revealed.
      return jsonFailure(error.message, 422, error.errors);
    }

    // A transient upstream failure is reported generically so it cannot be used
    // as an oracle either.
    return jsonFailure(
      "We could not process the request right now. Please try again.",
      502,
    );
  }

  return jsonSuccess(undefined, GENERIC_MESSAGE);
}
