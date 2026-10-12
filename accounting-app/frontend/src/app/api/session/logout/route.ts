import { jsonFailure, jsonSuccess } from "@/lib/api/response";
import { isSameOriginRequest } from "@/lib/auth/http";
import { logoutRequest } from "@/lib/auth/laravel";
import { clearSession, getSession } from "@/lib/auth/session";

/**
 * `POST /api/session/logout`
 *
 * Best-effort token revocation, then unconditional cookie removal. The cookie is
 * always cleared even if Laravel is unreachable, so the user is never stuck in a
 * half-signed-in state.
 */

export async function POST(request: Request): Promise<Response> {
  if (!isSameOriginRequest(request)) {
    return jsonFailure("The request was rejected.", 403);
  }

  const session = await getSession();

  if (session) {
    try {
      await logoutRequest(session.token);
    } catch {
      // Revocation is best effort; local sign-out must still succeed.
    }
  }

  await clearSession();

  return jsonSuccess(undefined, "Logged out successfully.");
}
