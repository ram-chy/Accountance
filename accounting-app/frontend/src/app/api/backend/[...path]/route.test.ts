// @vitest-environment node
/**
 * Route-handler tests for `ALL /api/backend/[...path]`.
 *
 * The handler is exercised directly with a real `NextRequest` while the
 * server-side session module is mocked, so the company-context refusal is
 * pinned at the HTTP boundary: the no-active-company case must answer 403
 * with the standard JSON envelope and must never reach the upstream fetch.
 */

import { afterEach, describe, expect, it, vi } from "vitest";
import { NextRequest } from "next/server";

import { GET, POST } from "./route";

const sessionMock = vi.hoisted(() => ({
  getSession: vi.fn<() => Promise<unknown>>(),
  clearSession: vi.fn<() => Promise<void>>(),
}));

vi.mock("@/lib/auth/session", () => sessionMock);

// `server-config` reads the base URL at module load; the hoisted assignment
// runs before the route module (and its config import) is evaluated.
vi.hoisted(() => {
  process.env.API_BASE_URL = "http://127.0.0.1:8000/api";
});

const context = (path: string[]) => ({
  params: Promise.resolve({ path }),
});

afterEach(() => {
  vi.clearAllMocks();
});

describe("backend proxy route handler", () => {
  it("answers 401 without an upstream call when there is no session", async () => {
    sessionMock.getSession.mockResolvedValue(null);
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const request = new NextRequest("http://localhost/api/backend/journals");
    const response = await GET(request, context(["journals"]));

    expect(response.status).toBe(401);
    await expect(response.json()).resolves.toEqual({
      success: false,
      message: "Unauthenticated.",
    });
    expect(fetchMock).not.toHaveBeenCalled();

    vi.unstubAllGlobals();
  });

  it("answers 403 with the JSON envelope and never fetches upstream when no company is active", async () => {
    sessionMock.getSession.mockResolvedValue({
      token: "jwt-token",
      expiresAt: Date.now() + 60_000,
      // No companyId: signed in, but no active company selected.
    });
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const request = new NextRequest("http://localhost/api/backend/journals", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ reference: "J-1" }),
    });
    const response = await POST(request, context(["journals"]));

    expect(response.status).toBe(403);
    await expect(response.json()).resolves.toEqual({
      success: false,
      message: "Select a company before making this request.",
    });

    // The refusal happens before any upstream I/O.
    expect(fetchMock).not.toHaveBeenCalled();

    // The envelope leaks nothing: no cookies, no upstream headers.
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(response.headers.get("www-authenticate")).toBeNull();

    vi.unstubAllGlobals();
  });

  it("still refuses a company-scoped read (GET) without an active company", async () => {
    sessionMock.getSession.mockResolvedValue({
      token: "jwt-token",
      expiresAt: Date.now() + 60_000,
    });
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const request = new NextRequest("http://localhost/api/backend/accounts");
    const response = await GET(request, context(["accounts"]));

    expect(response.status).toBe(403);
    expect(fetchMock).not.toHaveBeenCalled();

    vi.unstubAllGlobals();
  });

  it("forwards a company-scoped request with X-Company-Id when a company is active", async () => {
    sessionMock.getSession.mockResolvedValue({
      token: "jwt-token",
      companyId: 7,
      expiresAt: Date.now() + 60_000,
    });
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ success: true, data: [] }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const request = new NextRequest("http://localhost/api/backend/journals");
    const response = await GET(request, context(["journals"]));

    expect(response.status).toBe(200);
    expect(fetchMock).toHaveBeenCalledTimes(1);

    const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://127.0.0.1:8000/api/journals");
    const headers = options.headers as Record<string, string>;
    expect(headers["Authorization"]).toBe("Bearer jwt-token");
    expect(headers["X-Company-Id"]).toBe("7");

    vi.unstubAllGlobals();
  });

  it("does not require a company for global authenticated families", async () => {
    sessionMock.getSession.mockResolvedValue({
      token: "jwt-token",
      expiresAt: Date.now() + 60_000,
    });
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ success: true, data: [] }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const request = new NextRequest("http://localhost/api/backend/users");
    const response = await GET(request, context(["users"]));

    expect(response.status).toBe(200);
    const [, options] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = options.headers as Record<string, string>;
    expect(headers["X-Company-Id"]).toBeUndefined();

    vi.unstubAllGlobals();
  });

  it("clears the BFF session when the upstream answers 401", async () => {
    sessionMock.getSession.mockResolvedValue({
      token: "expired-token",
      companyId: 7,
      expiresAt: Date.now() + 60_000,
    });
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ success: false, message: "Unauthenticated." }), {
        status: 401,
        headers: { "Content-Type": "application/json" },
      }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const request = new NextRequest("http://localhost/api/backend/journals");
    const response = await GET(request, context(["journals"]));

    expect(response.status).toBe(401);
    expect(sessionMock.clearSession).toHaveBeenCalledTimes(1);

    vi.unstubAllGlobals();
  });
});
