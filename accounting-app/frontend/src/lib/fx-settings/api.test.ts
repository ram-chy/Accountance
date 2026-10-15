import { afterEach, describe, expect, it, vi } from "vitest";

import { fetchFxSetting, updateFxSetting } from "./api";

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

describe("fx settings", () => {
  it("reads the singleton through the proxy without an id", async () => {
    const fetchMock = mockJson(200, { success: true, data: { company_id: 1 } });

    await fetchFxSetting();

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/fx-settings");
    expect(firstRequest(fetchMock).options.method ?? "GET").toBe("GET");
  });

  it("passes an abort signal to fetch", async () => {
    const fetchMock = mockJson(200, { success: true, data: { company_id: 1 } });
    const controller = new AbortController();

    await fetchFxSetting(controller.signal);

    expect(firstRequest(fetchMock).options.signal).toBe(controller.signal);
  });

  it("PUTs both account ids together", async () => {
    const fetchMock = mockJson(200, { success: true, data: { company_id: 1 } });

    await updateFxSetting({ realized_gain_account_id: 30, realized_loss_account_id: 40 });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/fx-settings");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({
      realized_gain_account_id: 30,
      realized_loss_account_id: 40,
    });
  });

  it("clears the configuration by sending both fields as null", async () => {
    const fetchMock = mockJson(200, { success: true, data: { company_id: 1 } });

    await updateFxSetting({ realized_gain_account_id: null, realized_loss_account_id: null });

    expect(JSON.parse(String(firstRequest(fetchMock).options.body))).toEqual({
      realized_gain_account_id: null,
      realized_loss_account_id: null,
    });
  });
});
