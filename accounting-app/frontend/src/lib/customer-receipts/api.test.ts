import { afterEach, describe, expect, it, vi } from "vitest";

import {
  createCustomerReceipt,
  deleteCustomerReceipt,
  fetchCustomerReceipt,
  fetchCustomerReceipts,
  postCustomerReceipt,
  updateCustomerReceipt,
} from "./api";
import type { CustomerReceiptWritePayload } from "./types";

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

const payload: CustomerReceiptWritePayload = {
  customer_id: 4,
  receipt_date: "2026-06-30",
  amount: "600.0000",
  payment_account_id: 2,
  reference: null,
  notes: null,
  allocations: [{ sales_invoice_id: 11, amount: "600.0000" }],
};

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("fetchCustomerReceipts", () => {
  it("routes the list through the proxy with filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchCustomerReceipts({ status: "DRAFT", customer_id: 4, page: 2, per_page: 25 });

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("GET");
    expect(url).toContain("/api/backend/customer-receipts?");
    expect(url).toContain("status=DRAFT");
    expect(url).toContain("customer_id=4");
    expect(url).toContain("page=2");
  });

  it("omits empty filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchCustomerReceipts({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/customer-receipts");
  });
});

describe("fetchCustomerReceipt", () => {
  it("requests one receipt by id", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchCustomerReceipt(5);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/customer-receipts/5");
  });
});

describe("write operations", () => {
  it("creates through POST", async () => {
    const fetchMock = mockJson(201, { success: true, data: {} });

    await createCustomerReceipt(payload);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("POST");
    expect(url).toBe("/api/backend/customer-receipts");
  });

  it("updates through PUT", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await updateCustomerReceipt(5, payload);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("PUT");
    expect(url).toBe("/api/backend/customer-receipts/5");
  });

  it("posts a draft without a body", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await postCustomerReceipt(5);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("POST");
    expect(url).toBe("/api/backend/customer-receipts/5/post");
    expect(options.body).toBeUndefined();
  });

  it("deletes through DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteCustomerReceipt(5);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("DELETE");
    expect(url).toBe("/api/backend/customer-receipts/5");
  });
});
