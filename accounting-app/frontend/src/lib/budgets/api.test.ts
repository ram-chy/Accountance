import { afterEach, describe, expect, it, vi } from "vitest";

import {
  approveBudget,
  createBudget,
  createBudgetLine,
  deleteBudget,
  deleteBudgetLine,
  fetchBudget,
  fetchBudgets,
  fetchBudgetVariance,
  reviseBudget,
  updateBudget,
  updateBudgetLine,
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

function firstRequest(fetchMock: ReturnType<typeof vi.fn>): { url: string; options: RequestInit } {
  const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit];
  return { url, options };
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("budget reads", () => {
  it("omits every filter when unset", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchBudgets({});

    const { url } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/budgets");
  });

  it("keeps the year, status, search and paging filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchBudgets({ financial_year_id: 4, status: "APPROVED", search: "ops", page: 2, per_page: 25 });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("financial_year_id=4");
    expect(url).toContain("status=APPROVED");
    expect(url).toContain("search=ops");
    expect(url).toContain("page=2");
    expect(url).toContain("per_page=25");
  });

  it("shows one budget", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 5 } });

    await fetchBudget(5);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/budgets/5");
  });

  it("requests the variance report with the dimension filter", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchBudgetVariance(5, { dimension_id: 2, dimension_value_id: 7 });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/accounting/budgets/5/variance");
    expect(url).toContain("dimension_id=2");
    expect(url).toContain("dimension_value_id=7");
  });

  it("omits the dimension filter when it is unset", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchBudgetVariance(5, { dimension_id: null, dimension_value_id: null });

    const { url } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/budgets/5/variance");
  });
});

describe("budget writes", () => {
  it("creates with a POST body and drops an absent note", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 5 } });

    await createBudget({ financial_year_id: 4, code: "BUD-2026", name: "Ops plan", notes: null });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/budgets");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({
      financial_year_id: 4,
      code: "BUD-2026",
      name: "Ops plan",
      notes: null,
    });
  });

  it("updates with PUT, sending only the supplied keys", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 5 } });

    await updateBudget(5, { name: "Renamed" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/budgets/5");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({ name: "Renamed" });
  });

  it("deletes with DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteBudget(5);

    expect(firstRequest(fetchMock).options.method).toBe("DELETE");
  });

  it("approves with a bodyless POST", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 5 } });

    await approveBudget(5);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/budgets/5/approve");
    expect(options.method).toBe("POST");
    expect(options.body).toBeUndefined();
  });

  it("revises with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 6 } });

    await reviseBudget(5, { name: "v2", notes: "reforecast" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/budgets/5/revise");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({ name: "v2", notes: "reforecast" });
  });

  it("creates a line under its budget", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 11 } });

    await createBudgetLine(5, {
      account_id: 1,
      accounting_period_id: 2,
      amount: "1000.0000",
      description: null,
      dimensions: [{ dimension_id: 3, value_id: 9 }],
    });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/budgets/5/lines");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({
      account_id: 1,
      accounting_period_id: 2,
      amount: "1000.0000",
      description: null,
      dimensions: [{ dimension_id: 3, value_id: 9 }],
    });
  });

  it("updates a line with PUT and can clear the labels with an empty array", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 11 } });

    await updateBudgetLine(5, 11, { amount: "500.0000", dimensions: [] });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/budgets/5/lines/11");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({ amount: "500.0000", dimensions: [] });
  });

  it("deletes a line with DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteBudgetLine(5, 11);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/budgets/5/lines/11");
    expect(options.method).toBe("DELETE");
  });
});
