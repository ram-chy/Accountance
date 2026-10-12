import { afterEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";

import {
  fetchSession,
  loginSession,
  logoutSession,
  onUnauthorized,
  switchCompany,
} from "./client";

function mockJson(status: number, body: unknown): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn().mockResolvedValue(
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );

  vi.stubGlobal("fetch", fetchMock);

  return fetchMock;
}

function firstRequest(fetchMock: ReturnType<typeof vi.fn>): {
  url: string;
  options: RequestInit;
} {
  const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit];
  return { url, options };
}

const SNAPSHOT = {
  user: {
    id: 1,
    first_name: "Ada",
    last_name: "Lovelace",
    full_name: "Ada Lovelace",
    email: "ada@example.test",
    mobile_no: null,
    email_verified: true,
    is_active: true,
    roles: [],
    last_login_at: null,
    created_at: null,
    updated_at: null,
  },
  companies: [],
  activeCompanyId: null,
};

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("BFF client", () => {
  it("fetchSession hits the same-origin endpoint and unwraps data", async () => {
    const fetchMock = mockJson(200, { success: true, data: SNAPSHOT });

    await expect(fetchSession()).resolves.toEqual(SNAPSHOT);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/session/me");
    expect(options.method).toBe("GET");
  });

  it("fetchSession notifies unauthorized handlers on a 401", async () => {
    mockJson(401, { success: false, message: "Unauthenticated." });
    const handler = vi.fn();
    const unsubscribe = onUnauthorized(handler);

    await expect(fetchSession()).rejects.toBeInstanceOf(ApiError);
    expect(handler).toHaveBeenCalledTimes(1);

    unsubscribe();
  });

  it("loginSession POSTs credentials and does not trigger 401 handling", async () => {
    mockJson(200, { success: true, data: SNAPSHOT });

    await loginSession("ada@example.test", "hunter2");

    const { url, options } = firstRequest(vi.mocked(fetch));
    expect(url).toBe("/api/session/login");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({
      email: "ada@example.test",
      password: "hunter2",
    });
  });

  it("loginSession surfaces bad credentials as an ApiError without redirecting", async () => {
    mockJson(401, {
      success: false,
      message: "Invalid credentials.",
    });
    const handler = vi.fn();
    const unsubscribe = onUnauthorized(handler);

    const error = await loginSession("ada@example.test", "wrong").catch((e) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(401);
    expect(handler).not.toHaveBeenCalled();

    unsubscribe();
  });

  it("logoutSession issues a POST to the logout route", async () => {
    const fetchMock = mockJson(200, { success: true });

    await logoutSession();

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/session/logout");
    expect(options.method).toBe("POST");
  });

  it("switchCompany POSTs the company id", async () => {
    const company = {
      id: 3,
      name: "Acme",
      is_active: true,
      is_default: true,
    };
    const fetchMock = mockJson(200, { success: true, data: company });

    await switchCompany(3);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/session/company");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({ companyId: 3 });
  });
});