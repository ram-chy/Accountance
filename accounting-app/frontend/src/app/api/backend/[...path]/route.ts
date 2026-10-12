import { NextResponse, type NextRequest } from "next/server";

import {
  MAX_BODY_BYTES,
  PROXY_METHODS,
  buildUpstreamHeaders,
  buildUpstreamUrl,
  collectClientHeaders,
  filterResponseHeaders,
  validateProxyPath,
} from "@/lib/api/proxy-policy";
import { jsonFailure } from "@/lib/api/response";
import {
  SERVER_API_BASE_URL,
  isServerApiConfigured,
} from "@/lib/api/server-config";
import { clearSession, getSession } from "@/lib/auth/session";

/**
 * `ALL /api/backend/[...path]` — authenticated, company-aware API proxy.
 *
 * The browser never holds a JWT and never calls Laravel directly. Every
 * business request goes through this same-origin route, which:
 *
 *   1. requires a valid BFF session (encrypted httpOnly cookie);
 *   2. validates the path against an allow-list and rejects traversal;
 *   3. injects `Authorization` (from the cookie) and `X-Company-Id` (from the
 *      server-side active company) itself — neither is ever read from the
 *      browser;
 *   4. forwards the call server-to-server and streams the JSON back.
 *
 * An upstream `401` clears the BFF session so the client falls back to login;
 * every other status is passed through unchanged, preserving Laravel's error
 * envelope and validation details.
 */

type ProxyContext = { params: Promise<{ path?: string[] }> };

const ALLOWED_METHODS = new Set<string>(PROXY_METHODS);
const UPSTREAM_TIMEOUT_MS = 30_000;

function methodNotAllowed(): NextResponse {
  const response = jsonFailure(
    "The requested method is not supported.",
    405,
  );
  response.headers.set("Allow", PROXY_METHODS.join(", "));
  return response;
}

async function proxyRequest(
  request: NextRequest,
  context: ProxyContext,
): Promise<Response> {
  const method = request.method.toUpperCase();

  if (!ALLOWED_METHODS.has(method)) {
    return methodNotAllowed();
  }

  const session = await getSession();

  if (!session) {
    return jsonFailure("Unauthenticated.", 401);
  }

  const { path } = await context.params;
  const validation = validateProxyPath(path);

  if (!validation.ok) {
    return jsonFailure(validation.message, validation.status);
  }

  // Company-scoped routes must never be forwarded without an explicit active
  // company: the backend would otherwise fall back to the user's default and a
  // write could land against the wrong company.
  if (validation.requiresCompany && session.companyId === undefined) {
    return jsonFailure(
      "Select a company before making this request.",
      409,
    );
  }

  if (!isServerApiConfigured) {
    return jsonFailure(
      "The server API base URL is not configured. Set API_BASE_URL.",
      502,
    );
  }

  let body: ArrayBuffer | undefined;

  if (method !== "GET" && method !== "DELETE") {
    const declared = Number(request.headers.get("content-length") ?? "");

    if (Number.isFinite(declared) && declared > MAX_BODY_BYTES) {
      return jsonFailure("The request payload is too large.", 413);
    }

    body = await request.arrayBuffer();

    if (body.byteLength > MAX_BODY_BYTES) {
      return jsonFailure("The request payload is too large.", 413);
    }
  }

  const url = buildUpstreamUrl(
    SERVER_API_BASE_URL,
    validation.segments,
    request.nextUrl.search,
  );
  const headers = buildUpstreamHeaders({
    token: session.token,
    companyId: validation.requiresCompany ? session.companyId : undefined,
    clientHeaders: collectClientHeaders(request.headers),
  });

  let upstream: Response;

  try {
    upstream = await fetch(url, {
      method,
      headers,
      body,
      redirect: "manual",
      signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
    });
  } catch (error) {
    if (error instanceof Error && error.name === "TimeoutError") {
      return jsonFailure("The accounting service took too long to respond.", 504);
    }

    return jsonFailure(
      "Could not reach the accounting service. Please try again.",
      502,
    );
  }

  if (upstream.status === 401) {
    await clearSession();
  }

  const responseHeaders = filterResponseHeaders(upstream.headers);

  if (upstream.status === 204 || upstream.status === 205) {
    return new NextResponse(null, {
      status: upstream.status,
      headers: responseHeaders,
    });
  }

  const text = await upstream.text();

  return new NextResponse(text, {
    status: upstream.status,
    headers: responseHeaders,
  });
}

export const GET = proxyRequest;
export const POST = proxyRequest;
export const PUT = proxyRequest;
export const PATCH = proxyRequest;
export const DELETE = proxyRequest;
