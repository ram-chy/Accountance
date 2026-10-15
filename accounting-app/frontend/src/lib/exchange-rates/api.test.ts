import { afterEach, describe, expect, it, vi } from "vitest";

import {
  activateExchangeRate,
  createExchangeRate,
  deactivateExchangeRate,
  fetchExchangeRate,
  fetchExchangeRates,
  updateExchangeRate,
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

describe("exchange-rate reads", () => {
  it("routes the list through the proxy with no filters by default", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchExchangeRates();

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/exchange-rates");
  });

  it("maps the pair and active_only filters to the verified query string", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchExchangeRates({ from_currency_id: 1, to_currency_id: 2, active_only: true });

    expect(firstRequest(fetchMock).url).toBe(
      "/api/backend/accounting/exchange-rates?from_currency_id=1&to_currency_id=2&active_only=1",
    );
  });

  it("passes an abort signal to fetch", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });
    const controller = new AbortController();

    await fetchExchangeRates({}, controller.signal);

    expect(firstRequest(fetchMock).options.signal).toBe(controller.signal);
  });

  it("fetches one rate", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await fetchExchangeRate(9);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/exchange-rates/9");
  });
});

describe("exchange-rate writes", () => {
  it("creates a rate with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 1 } });

    await createExchangeRate({
      from_currency_id: 1,
      to_currency_id: 2,
      rate: "83.5000000000",
      effective_date: "2026-04-01",
      source: "ECB",
    });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/exchange-rates");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({
      from_currency_id: 1,
      to_currency_id: 2,
      rate: "83.5000000000",
      effective_date: "2026-04-01",
      source: "ECB",
    });
  });

  it("updates a rate with a PUT body that never carries the pair", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await updateExchangeRate(9, { rate: "84.0000000000", effective_date: "2026-04-02", source: null });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/exchange-rates/9");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({
      rate: "84.0000000000",
      effective_date: "2026-04-02",
      source: null,
    });
  });

  it("activates and deactivates via bodyless POST sub-resources", async () => {
    const activateMock = mockJson(200, { success: true, data: { id: 9 } });
    await activateExchangeRate(9);
    expect(firstRequest(activateMock).url).toBe(
      "/api/backend/accounting/exchange-rates/9/activate",
    );
    expect(firstRequest(activateMock).options.method).toBe("POST");

    const deactivateMock = mockJson(200, { success: true, data: { id: 9 } });
    await deactivateExchangeRate(9);
    expect(firstRequest(deactivateMock).url).toBe(
      "/api/backend/accounting/exchange-rates/9/deactivate",
    );
    expect(firstRequest(deactivateMock).options.method).toBe("POST");
  });
});
