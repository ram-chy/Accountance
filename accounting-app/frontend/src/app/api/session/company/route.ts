import { ApiError } from "@/lib/api/errors";
import { jsonFailure, jsonSuccess } from "@/lib/api/response";
import { isSameOriginRequest, readJsonBody } from "@/lib/auth/http";
import { switchCompanyRequest } from "@/lib/auth/laravel";
import { clearSession, getSession, writeSession } from "@/lib/auth/session";

/**
 * `POST /api/session/company`
 *
 * Validates the requested company with Laravel's switch endpoint (membership and
 * active-state are enforced server-side, not in the UI) and persists the choice
 * in the cookie. The token is re-sealed unchanged; only `companyId` moves.
 */

export async function POST(request: Request): Promise<Response> {
  if (!isSameOriginRequest(request)) {
    return jsonFailure("The request was rejected.", 403);
  }

  const session = await getSession();

  if (!session) {
    return jsonFailure("Unauthenticated.", 401);
  }

  const body = await readJsonBody(request);
  const companyId = Number(body?.companyId);

  if (!Number.isInteger(companyId) || companyId <= 0) {
    return jsonFailure("The given data was invalid.", 422, {
      companyId: ["The selected company is invalid."],
    });
  }

  try {
    const company = await switchCompanyRequest(session.token, companyId);

    await writeSession({
      token: session.token,
      companyId: company.id,
      expiresAt: session.expiresAt,
    });

    return jsonSuccess(company, "Company switched successfully.");
  } catch (error) {
    if (error instanceof ApiError) {
      if (error.status === 401) {
        await clearSession();
      }

      return jsonFailure(error.message, error.status > 0 ? error.status : 502);
    }

    return jsonFailure("Unable to switch company right now. Please try again.", 502);
  }
}
