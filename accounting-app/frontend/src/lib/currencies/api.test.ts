import { afterEach, describe, expect, it, vi } from "vitest";

import {
  activateCurrency,
  createCurrency,
  deactivateCurrency,
  fetchCurrencies,
  fetchCurrency,
  updateCurrency,
} from "./api";

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

describe("currency reads", () => {
  it("routes the list through the proxy and omits the filter by default", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchCurrencies();

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/currencies");
  });

  it("maps active_only to the verified query string", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchCurrencies({ active_only: true });

    expect(firstRequest(fetchMock).url).toBe(
      "/api/backend/accounting/currencies?active_only=1",
    );
  });

  it("omits the filter when active_only is false", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchCurrencies({ active_only: false });

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/currencies");
  });

  it("passes an abort signal to fetch", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });
    const controller = new AbortController();

    await fetchCurrencies({}, controller.signal);

    expect(firstRequest(fetchMock).options.signal).toBe(controller.signal);
  });

  it("fetches one currency", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await fetchCurrency(9);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/currencies/9");
  });
});

describe("currency writes", () => {
  it("creates a currency with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 1 } });

    await createCurrency({ code: "USD", name: "US Dollar", symbol: "$", decimal_precision: 2 });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/currencies");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({
      code: "USD",
      name: "US Dollar",
      symbol: "$",
      decimal_precision: 2,
    });
  });

  it("updates a currency with a PUT body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await updateCurrency(9, { name: "US Dollar (renamed)" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/currencies/9");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({ name: "US Dollar (renamed)" });
  });

  it("activates and deactivates via bodyless POST sub-resources", async () => {
    const activateMock = mockJson(200, { success: true, data: { id: 9 } });
    await activateCurrency(9);
    expect(firstRequest(activateMock).url).toBe(
      "/api/backend/accounting/currencies/9/activate",
    );
    expect(firstRequest(activateMock).options.method).toBe("POST");

    const deactivateMock = mockJson(200, { success: true, data: { id: 9 } });
    await deactivateCurrency(9);
    expect(firstRequest(deactivateMock).url).toBe(
      "/api/backend/accounting/currencies/9/deactivate",
    );
    expect(firstRequest(deactivateMock).options.method).toBe("POST");
  });
});
