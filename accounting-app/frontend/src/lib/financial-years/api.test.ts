import { afterEach, describe, expect, it, vi } from "vitest";

import {
  closeFinancialYear,
  createFinancialYear,
  fetchFinancialYears,
  generateFinancialYearPeriods,
  updateFinancialYear,
} from "./api";
import type { FinancialYearWritePayload } from "./types";

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

const payload: FinancialYearWritePayload = {
  name: "2026-2027",
  start_date: "2026-04-01",
  end_date: "2027-03-31",
};

describe("fetchFinancialYears", () => {
  it("routes through the proxy and omits empty filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchFinancialYears({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/financial-years");
  });

  it("maps filters to the verified query string", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchFinancialYears({ status: "OPEN", page: 2, per_page: 50 });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/accounting/financial-years?");
    expect(url).toContain("status=OPEN");
    expect(url).toContain("page=2");
    expect(url).toContain("per_page=50");
  });

  it("passes an abort signal to fetch", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });
    const controller = new AbortController();

    await fetchFinancialYears({}, controller.signal);

    expect(firstRequest(fetchMock).options.signal).toBe(controller.signal);
  });
});

describe("financial-year writes", () => {
  it("creates a year with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 1 } });

    await createFinancialYear(payload);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/financial-years");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual(payload);
  });

  it("updates a year with a PUT body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 3 } });

    await updateFinancialYear(3, { name: "Renamed" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/financial-years/3");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({ name: "Renamed" });
  });

  it("generates periods with a bodyless POST sub-resource", async () => {
    const fetchMock = mockJson(201, { success: true, data: {} });

    await generateFinancialYearPeriods(5);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/financial-years/5/periods/generate");
    expect(options.method).toBe("POST");
    expect(options.body).toBeUndefined();
  });

  it("closes a year with a bodyless POST sub-resource", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 5 } });

    await closeFinancialYear(5);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/financial-years/5/close");
    expect(options.method).toBe("POST");
  });
});
