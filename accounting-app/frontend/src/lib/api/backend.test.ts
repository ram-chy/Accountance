import { afterEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";

import { backendGet, backendPost } from "./backend";
import { onUnauthorized } from "./unauthorized";

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

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("backend client", () => {
  it("routes business calls through the same-origin proxy and unwraps data", async () => {
    const fetchMock = mockJson(200, {
      success: true,
      data: [{ id: 1, name: "Cash" }],
    });

    await expect(backendGet("accounts")).resolves.toEqual([
      { id: 1, name: "Cash" },
    ]);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounts");
    expect(options.method).toBe("GET");
  });

  it("strips a leading slash from the path", async () => {
    const fetchMock = mockJson(200, { success: true, data: null });

    await backendGet("/sales-invoices/12");

    expect(firstRequest(fetchMock).url).toBe("/api/backend/sales-invoices/12");
  });

  it("POSTs a JSON body through the proxy", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await backendPost("journals", { reference: "J-1" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/journals");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({ reference: "J-1" });
  });

  it("notifies unauthorized handlers on a 401", async () => {
    mockJson(401, { success: false, message: "Unauthenticated." });
    const handler = vi.fn();
    const unsubscribe = onUnauthorized(handler);

    await expect(backendGet("accounts")).rejects.toBeInstanceOf(ApiError);
    expect(handler).toHaveBeenCalledTimes(1);

    unsubscribe();
  });

  it("does not notify unauthorized handlers on a 403", async () => {
    mockJson(403, { success: false, message: "Forbidden." });
    const handler = vi.fn();
    const unsubscribe = onUnauthorized(handler);

    await expect(backendGet("accounts")).rejects.toBeInstanceOf(ApiError);
    expect(handler).not.toHaveBeenCalled();

    unsubscribe();
  });
});
