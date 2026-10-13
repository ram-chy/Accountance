import { afterEach, describe, expect, it, vi } from "vitest";

import {
  createSalesInvoice,
  deleteSalesInvoice,
  fetchSalesInvoice,
  fetchSalesInvoices,
  postSalesInvoice,
  updateSalesInvoice,
} from "./api";
import type { SalesInvoiceWritePayload } from "./types";

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

const payload: SalesInvoiceWritePayload = {
  customer_id: 4,
  invoice_date: "2026-06-01",
  due_date: "2026-06-30",
  notes: null,
  tax_account_id: null,
  lines: [
    {
      description: "Consulting",
      quantity: "1",
      unit_price: "1000",
      discount: "0",
      tax_rate: "0",
      revenue_account_id: 30,
    },
  ],
};

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("fetchSalesInvoices", () => {
  it("routes the list through the proxy with filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchSalesInvoices({ status: "DRAFT", customer_id: 4, outstanding: true });

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("GET");
    expect(url).toContain("/api/backend/sales-invoices?");
    expect(url).toContain("status=DRAFT");
    expect(url).toContain("customer_id=4");
    expect(url).toContain("outstanding=true");
  });

  it("omits empty filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchSalesInvoices({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/sales-invoices");
  });
});

describe("fetchSalesInvoice", () => {
  it("requests one invoice by id", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchSalesInvoice(7);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/sales-invoices/7");
  });
});

describe("write operations", () => {
  it("creates through POST", async () => {
    const fetchMock = mockJson(201, { success: true, data: {} });

    await createSalesInvoice(payload);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("POST");
    expect(url).toBe("/api/backend/sales-invoices");
  });

  it("updates through PUT", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await updateSalesInvoice(7, payload);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("PUT");
    expect(url).toBe("/api/backend/sales-invoices/7");
  });

  it("posts a draft without a body", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await postSalesInvoice(7);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("POST");
    expect(url).toBe("/api/backend/sales-invoices/7/post");
    expect(options.body).toBeUndefined();
  });

  it("deletes through DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteSalesInvoice(7);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("DELETE");
    expect(url).toBe("/api/backend/sales-invoices/7");
  });
});
