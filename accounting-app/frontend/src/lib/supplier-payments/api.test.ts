import { afterEach, describe, expect, it, vi } from "vitest";

import {
  createSupplierPayment,
  deleteSupplierPayment,
  fetchSupplierPayment,
  fetchSupplierPayments,
  postSupplierPayment,
  updateSupplierPayment,
} from "./api";
import type { SupplierPaymentWritePayload } from "./types";

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

const payload: SupplierPaymentWritePayload = {
  supplier_id: 3,
  payment_date: "2026-05-02",
  amount: "600.0000",
  payment_account_id: 2,
  reference: null,
  notes: null,
  allocations: [{ purchase_bill_id: 11, amount: "600.0000" }],
};

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("fetchSupplierPayments", () => {
  it("routes the list through the proxy with filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchSupplierPayments({ status: "DRAFT", supplier_id: 3, page: 2, per_page: 25 });

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("GET");
    expect(url).toContain("/api/backend/supplier-payments?");
    expect(url).toContain("status=DRAFT");
    expect(url).toContain("supplier_id=3");
    expect(url).toContain("page=2");
  });

  it("omits empty filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchSupplierPayments({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/supplier-payments");
  });
});

describe("fetchSupplierPayment", () => {
  it("requests one payment by id", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchSupplierPayment(5);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/supplier-payments/5");
  });
});

describe("write operations", () => {
  it("creates through POST", async () => {
    const fetchMock = mockJson(201, { success: true, data: {} });

    await createSupplierPayment(payload);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("POST");
    expect(url).toBe("/api/backend/supplier-payments");
  });

  it("updates through PUT", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await updateSupplierPayment(5, payload);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("PUT");
    expect(url).toBe("/api/backend/supplier-payments/5");
  });

  it("posts a draft without a body", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await postSupplierPayment(5);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("POST");
    expect(url).toBe("/api/backend/supplier-payments/5/post");
    expect(options.body).toBeUndefined();
  });

  it("deletes through DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteSupplierPayment(5);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("DELETE");
    expect(url).toBe("/api/backend/supplier-payments/5");
  });
});
