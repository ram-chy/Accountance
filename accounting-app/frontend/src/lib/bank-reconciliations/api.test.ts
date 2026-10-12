import { afterEach, describe, expect, it, vi } from "vitest";

import {
  addBankReconciliationItem,
  completeBankReconciliation,
  deleteBankReconciliation,
  fetchBankReconciliation,
  fetchBankReconciliationMovements,
  fetchBankReconciliations,
  removeBankReconciliationItem,
  reopenBankReconciliation,
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

describe("fetchBankReconciliations", () => {
  it("routes through the proxy and omits empty filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchBankReconciliations({ status: "", from_date: "", to_date: "" });

    expect(firstRequest(fetchMock).url).toBe("/api/backend/bank-reconciliations");
  });

  it("maps filters to the verified query string", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchBankReconciliations({
      status: "IN_PROGRESS",
      from_date: "2026-04-01",
      to_date: "2026-04-30",
      page: 2,
      per_page: 25,
    });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/bank-reconciliations?");
    expect(url).toContain("status=IN_PROGRESS");
    expect(url).toContain("from_date=2026-04-01");
    expect(url).toContain("to_date=2026-04-30");
    expect(url).toContain("page=2");
    expect(url).toContain("per_page=25");
  });

  it("passes an abort signal to fetch", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });
    const controller = new AbortController();

    await fetchBankReconciliations({ status: "", from_date: "", to_date: "" }, controller.signal);

    expect(firstRequest(fetchMock).options.signal).toBe(controller.signal);
  });
});

describe("bank reconciliation reads", () => {
  it("fetches one reconciliation", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await fetchBankReconciliation(9);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/bank-reconciliations/9");
  });

  it("fetches the movements", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchBankReconciliationMovements(9);

    expect(firstRequest(fetchMock).url).toBe(
      "/api/backend/bank-reconciliations/9/movements",
    );
  });
});

describe("bank reconciliation writes", () => {
  it("clears a movement with a POST body", async () => {
    const fetchMock = mockJson(201, { id: 88 });

    await addBankReconciliationItem(9, { journal_line_id: 42 });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/bank-reconciliations/9/items");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({ journal_line_id: 42 });
  });

  it("removes an item by id", async () => {
    const fetchMock = mockJson(200, { success: true });

    await removeBankReconciliationItem(9, 88);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/bank-reconciliations/9/items/88");
    expect(options.method).toBe("DELETE");
  });

  it("completes and reopens via bodyless POST sub-resources", async () => {
    const completeMock = mockJson(200, { success: true, data: { id: 9 } });
    await completeBankReconciliation(9);
    expect(firstRequest(completeMock).url).toBe("/api/backend/bank-reconciliations/9/complete");
    expect(firstRequest(completeMock).options.method).toBe("POST");

    const reopenMock = mockJson(200, { success: true, data: { id: 9 } });
    await reopenBankReconciliation(9);
    expect(firstRequest(reopenMock).url).toBe("/api/backend/bank-reconciliations/9/reopen");
    expect(firstRequest(reopenMock).options.method).toBe("POST");
  });

  it("deletes a reconciliation", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteBankReconciliation(9);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/bank-reconciliations/9");
    expect(options.method).toBe("DELETE");
  });
});
