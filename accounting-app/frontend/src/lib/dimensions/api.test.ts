import { afterEach, describe, expect, it, vi } from "vitest";

import {
  activateFinancialDimension,
  activateFinancialDimensionValue,
  createFinancialDimension,
  createFinancialDimensionValue,
  deactivateFinancialDimension,
  deactivateFinancialDimensionValue,
  deleteFinancialDimension,
  deleteFinancialDimensionValue,
  fetchFinancialDimension,
  fetchFinancialDimensions,
  fetchFinancialDimensionValues,
  updateFinancialDimension,
  updateFinancialDimensionValue,
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

describe("financial dimension reads", () => {
  it("omits every filter when unset", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchFinancialDimensions({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/dimensions");
  });

  it("maps the active filter to 1/0 and keeps type and search", async () => {
    const active = mockJson(200, { success: true, data: [] });
    await fetchFinancialDimensions({ type: "COST_CENTER", is_active: true, search: "sales" });
    const activeUrl = firstRequest(active).url;
    expect(activeUrl).toContain("type=COST_CENTER");
    expect(activeUrl).toContain("is_active=1");
    expect(activeUrl).toContain("search=sales");

    const inactive = mockJson(200, { success: true, data: [] });
    await fetchFinancialDimensions({ is_active: false });
    expect(firstRequest(inactive).url).toContain("is_active=0");
  });

  it("shows one dimension", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 3 } });

    await fetchFinancialDimension(3);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/dimensions/3");
  });

  it("lists a dimension's values with the is_active filter", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchFinancialDimensionValues(3, { is_active: true, per_page: 100 });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/accounting/dimensions/3/values");
    expect(url).toContain("is_active=1");
    expect(url).toContain("per_page=100");
  });
});

describe("financial dimension writes", () => {
  it("creates with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 3 } });

    await createFinancialDimension({ type: "PROJECT", code: "PRJ", name: "Projects" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/dimensions");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({ type: "PROJECT", code: "PRJ", name: "Projects" });
  });

  it("updates with PUT and never sends the type", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 3 } });

    await updateFinancialDimension(3, { name: "Renamed" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/dimensions/3");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({ name: "Renamed" });
  });

  it("deletes with DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteFinancialDimension(3);

    expect(firstRequest(fetchMock).options.method).toBe("DELETE");
  });

  it("activates and deactivates with a bodyless POST", async () => {
    const active = mockJson(200, { success: true, data: { id: 3 } });
    await activateFinancialDimension(3);
    const activated = firstRequest(active);
    expect(activated.url).toBe("/api/backend/accounting/dimensions/3/activate");
    expect(activated.options.method).toBe("POST");
    expect(activated.options.body).toBeUndefined();

    const inactive = mockJson(200, { success: true, data: { id: 3 } });
    await deactivateFinancialDimension(3);
    expect(firstRequest(inactive).url).toBe("/api/backend/accounting/dimensions/3/deactivate");
  });

  it("creates a value under its dimension", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 9 } });

    await createFinancialDimensionValue(3, { code: "CC-001", name: "Sales" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/dimensions/3/values");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({ code: "CC-001", name: "Sales" });
  });

  it("updates, deletes and toggles a value under its dimension", async () => {
    const update = mockJson(200, { success: true, data: { id: 9 } });
    await updateFinancialDimensionValue(3, 9, { code: "CC-002", name: "Ops" });
    const updated = firstRequest(update);
    expect(updated.url).toBe("/api/backend/accounting/dimensions/3/values/9");
    expect(updated.options.method).toBe("PUT");

    const remove = mockJson(200, { success: true });
    await deleteFinancialDimensionValue(3, 9);
    expect(firstRequest(remove).options.method).toBe("DELETE");

    const activate = mockJson(200, { success: true, data: { id: 9 } });
    await activateFinancialDimensionValue(3, 9);
    const activated = firstRequest(activate);
    expect(activated.url).toBe("/api/backend/accounting/dimensions/3/values/9/activate");
    expect(activated.options.body).toBeUndefined();

    const deactivate = mockJson(200, { success: true, data: { id: 9 } });
    await deactivateFinancialDimensionValue(3, 9);
    expect(firstRequest(deactivate).url).toBe("/api/backend/accounting/dimensions/3/values/9/deactivate");
  });
});
