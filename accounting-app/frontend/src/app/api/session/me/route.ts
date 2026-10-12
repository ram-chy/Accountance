import { ApiError } from "@/lib/api/errors";
import { jsonFailure, jsonSuccess } from "@/lib/api/response";
import { clearSession, getSession, writeSession } from "@/lib/auth/session";
import { buildSessionSnapshot } from "@/lib/auth/snapshot";

/**
 * `GET /api/session/me`
 *
 * Re-reads the user, their companies and the active company from Laravel using
 * the token sealed in the cookie. This is the browser's source of truth after a
 * refresh or a full navigation (`proxy.ts` only checks cookie presence/expiry and
 * never calls Laravel).
 *
 * If the active company is no longer valid (membership revoked, company
 * deactivated) the cookie is quietly repointed at the resolved fallback.
 */

export async function GET(): Promise<Response> {
  const session = await getSession();

  if (!session) {
    return jsonFailure("Unauthenticated.", 401);
  }

  try {
    const built = await buildSessionSnapshot(session.token, session.companyId);

    if (
      session.companyId !== undefined &&
      session.companyId !== (built.companyId ?? undefined)
    ) {
      await writeSession({
        token: session.token,
        companyId: built.companyId ?? undefined,
        expiresAt: session.expiresAt,
      });
    }

    return jsonSuccess(built.snapshot);
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      await clearSession();
      return jsonFailure("Unauthenticated.", 401);
    }

    return jsonFailure(
      "Unable to load your session right now. Please try again.",
      502,
    );
  }
}
