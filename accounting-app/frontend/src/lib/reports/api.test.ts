import { afterEach, describe, expect, it, vi } from "vitest";

import {
  fetchBalanceSheet,
  fetchCashBank,
  fetchGeneralLedger,
  fetchProfitLoss,
  fetchTrialBalance,
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

describe("fetchTrialBalance", () => {
  it("routes through the proxy and includes the date range and zero flag", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchTrialBalance({ from: "2026-01-01", to: "2026-12-31", include_zero_balances: true });

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("GET");
    expect(url).toContain("/api/backend/accounting/reports/trial-balance?");
    expect(url).toContain("from=2026-01-01");
    expect(url).toContain("to=2026-12-31");
    expect(url).toContain("include_zero_balances=1");
  });

  it("omits empty filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchTrialBalance({ from: "", to: "", include_zero_balances: false });

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/reports/trial-balance");
  });
});

describe("fetchProfitLoss", () => {
  it("includes only the supplied bounds", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchProfitLoss({ from: "2026-03-01", to: "" });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/accounting/reports/profit-loss?");
    expect(url).toContain("from=2026-03-01");
    expect(url).not.toContain("to=");
  });
});

describe("fetchGeneralLedger", () => {
  it("sends the account id alongside the date range", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchGeneralLedger({ account_id: 42, from: "2026-01-01", to: "" });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/accounting/reports/general-ledger?");
    expect(url).toContain("account_id=42");
    expect(url).toContain("from=2026-01-01");
  });
});

describe("fetchBalanceSheet", () => {
  it("sends the as-of date as `to`", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchBalanceSheet({ from: "", to: "2026-12-31" });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/accounting/reports/balance-sheet?");
    expect(url).toContain("to=2026-12-31");
    expect(url).not.toContain("from=");
  });
});

describe("fetchCashBank", () => {
  it("sends the account id and date range", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchCashBank({ account_id: 7, from: "2026-02-01", to: "2026-02-28" });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/accounting/reports/cash-bank?");
    expect(url).toContain("account_id=7");
    expect(url).toContain("from=2026-02-01");
    expect(url).toContain("to=2026-02-28");
  });
});

describe("abort signal", () => {
  it("forwards the caller's signal", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });
    const controller = new AbortController();

    await fetchBalanceSheet({ from: "", to: "" }, controller.signal);

    expect(firstRequest(fetchMock).options.signal).toBe(controller.signal);
  });
});
