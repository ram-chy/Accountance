import { afterEach, describe, expect, it, vi } from "vitest";

import {
  createPurchaseBill,
  deletePurchaseBill,
  fetchPurchaseBill,
  fetchPurchaseBills,
  postPurchaseBill,
  updatePurchaseBill,
} from "./api";
import type { PurchaseBillWritePayload } from "./types";

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

const payload: PurchaseBillWritePayload = {
  supplier_id: 3,
  bill_date: "2026-05-01",
  due_date: "2026-05-31",
  notes: null,
  tax_account_id: null,
  lines: [
    {
      description: null,
      quantity: "1",
      unit_cost: "100",
      discount: "0",
      tax_rate: "0",
      expense_account_id: 7,
    },
  ],
};

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("fetchPurchaseBills", () => {
  it("routes the list through the proxy with filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchPurchaseBills({ status: "POSTED", supplier_id: 3, outstanding: true, page: 2, per_page: 25 });

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("GET");
    expect(url).toContain("/api/backend/purchase-bills?");
    expect(url).toContain("status=POSTED");
    expect(url).toContain("supplier_id=3");
    expect(url).toContain("outstanding=true");
    expect(url).toContain("page=2");
  });

  it("omits empty filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchPurchaseBills({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/purchase-bills");
  });
});

describe("fetchPurchaseBill", () => {
  it("requests one bill by id", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchPurchaseBill(5);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/purchase-bills/5");
  });
});

describe("write operations", () => {
  it("creates through POST", async () => {
    const fetchMock = mockJson(201, { success: true, data: {} });

    await createPurchaseBill(payload);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("POST");
    expect(url).toBe("/api/backend/purchase-bills");
  });

  it("updates through PUT", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await updatePurchaseBill(5, payload);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("PUT");
    expect(url).toBe("/api/backend/purchase-bills/5");
  });

  it("posts a draft without a body", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await postPurchaseBill(5);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("POST");
    expect(url).toBe("/api/backend/purchase-bills/5/post");
    expect(options.body).toBeUndefined();
  });

  it("deletes through DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deletePurchaseBill(5);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("DELETE");
    expect(url).toBe("/api/backend/purchase-bills/5");
  });
});
