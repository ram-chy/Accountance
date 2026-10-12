import { ApiError } from "@/lib/api/errors";
import { jsonFailure, jsonSuccess } from "@/lib/api/response";
import { isSameOriginRequest, readJsonBody } from "@/lib/auth/http";
import { loginRequest } from "@/lib/auth/laravel";
import { writeSession } from "@/lib/auth/session";
import { buildSessionSnapshot } from "@/lib/auth/snapshot";

/**
 * `POST /api/session/login`
 *
 * Verifies credentials with Laravel, seals the returned JWT in the encrypted
 * httpOnly cookie, and returns the same session snapshot as `/session/me`. The
 * token itself is never in the response body.
 *
 * Login failures are intentionally indistinguishable (unknown email, wrong
 * password, inactive account all return Laravel's single `401` message), so the
 * UI must not try to explain why.
 */

type ValidationResult =
  | { ok: true; email: string; password: string }
  | { ok: false; errors: Record<string, string[]> };

function validate(body: Record<string, unknown> | null): ValidationResult {
  const email = typeof body?.email === "string" ? body.email.trim() : "";
  const password = typeof body?.password === "string" ? body.password : "";
  const errors: Record<string, string[]> = {};

  if (!email) {
    errors.email = ["The email field is required."];
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errors.email = ["The email field must be a valid email address."];
  }

  if (!password) {
    errors.password = ["The password field is required."];
  }

  if (Object.keys(errors).length > 0) {
    return { ok: false, errors };
  }

  return { ok: true, email, password };
}

export async function POST(request: Request): Promise<Response> {
  if (!isSameOriginRequest(request)) {
    return jsonFailure("The request was rejected.", 403);
  }

  const validation = validate(await readJsonBody(request));

  if (!validation.ok) {
    return jsonFailure("The given data was invalid.", 422, validation.errors);
  }

  let result;

  try {
    result = await loginRequest(validation.email, validation.password);
  } catch (error) {
    if (error instanceof ApiError) {
      // `status 0` means the request never reached Laravel (no config, network
      // failure). Report it as a server-side problem, never as bad credentials.
      return jsonFailure(error.message, error.status > 0 ? error.status : 502);
    }

    return jsonFailure("Unable to sign in right now. Please try again.", 502);
  }

  const seconds = Number.isFinite(result.expires_in) ? result.expires_in : 3600;
  const expiresAt = Date.now() + seconds * 1000;

  let built;

  try {
    built = await buildSessionSnapshot(result.token);
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      return jsonFailure(
        "Your session could not be established. Please sign in again.",
        401,
      );
    }

    // The token is valid but the profile/company reads failed transiently.
    // Establish the session anyway; `/session/me` will refresh it. Permission
    // gating fails closed until then because no permissions are known.
    built = {
      snapshot: { user: result.user, companies: [], activeCompanyId: null },
      companyId: null,
    };
  }

  await writeSession({
    token: result.token,
    companyId: built.companyId ?? undefined,
    expiresAt,
  });

  return jsonSuccess(built.snapshot, "Login successful.");
}
