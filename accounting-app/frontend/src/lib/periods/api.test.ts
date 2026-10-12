import { afterEach, describe, expect, it, vi } from "vitest";

import {
  closeAccountingPeriod,
  createAccountingPeriod,
  fetchAccountingPeriod,
  fetchAccountingPeriods,
  fetchPeriodClosingCheck,
  reopenAccountingPeriod,
  updateAccountingPeriod,
} from "./api";
import type { PeriodWritePayload } from "./types";

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

const payload: PeriodWritePayload = {
  name: "April 2026",
  start_date: "2026-04-01",
  end_date: "2026-04-30",
  financial_year_id: null,
};

describe("fetchAccountingPeriods", () => {
  it("routes through the proxy and omits empty filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchAccountingPeriods({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/periods");
  });

  it("maps filters to the verified query string", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchAccountingPeriods({
      status: "OPEN",
      financial_year_id: 4,
      from_date: "2026-04-01",
      to_date: "2026-06-30",
      page: 2,
      per_page: 50,
    });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/accounting/periods?");
    expect(url).toContain("status=OPEN");
    expect(url).toContain("financial_year_id=4");
    expect(url).toContain("from_date=2026-04-01");
    expect(url).toContain("to_date=2026-06-30");
    expect(url).toContain("page=2");
  });

  it("passes an abort signal to fetch", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });
    const controller = new AbortController();

    await fetchAccountingPeriods({}, controller.signal);

    expect(firstRequest(fetchMock).options.signal).toBe(controller.signal);
  });
});

describe("period reads", () => {
  it("fetches one period", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await fetchAccountingPeriod(9);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/periods/9");
  });

  it("fetches the closing check", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchPeriodClosingCheck(9);

    expect(firstRequest(fetchMock).url).toBe(
      "/api/backend/accounting/periods/9/closing-check",
    );
  });
});

describe("period writes", () => {
  it("creates a period with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 1 } });

    await createAccountingPeriod(payload);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/periods");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual(payload);
  });

  it("updates a period with a PUT body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await updateAccountingPeriod(9, {
      name: "April",
      start_date: "2026-04-01",
      end_date: "2026-04-30",
    });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/periods/9");
    expect(options.method).toBe("PUT");
  });

  it("closes and reopens via bodyless POST sub-resources", async () => {
    const closeMock = mockJson(200, { success: true, data: { id: 9 } });
    await closeAccountingPeriod(9);
    expect(firstRequest(closeMock).url).toBe("/api/backend/accounting/periods/9/close");
    expect(firstRequest(closeMock).options.method).toBe("POST");

    const reopenMock = mockJson(200, { success: true, data: { id: 9 } });
    await reopenAccountingPeriod(9);
    expect(firstRequest(reopenMock).url).toBe("/api/backend/accounting/periods/9/reopen");
    expect(firstRequest(reopenMock).options.method).toBe("POST");
  });
});
