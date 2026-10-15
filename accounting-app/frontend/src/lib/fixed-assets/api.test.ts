import { afterEach, describe, expect, it, vi } from "vitest";

import {
  capitalizeFixedAsset,
  createFixedAssetCash,
  createFixedAssetSupplierCredit,
  deleteFixedAsset,
  depreciateFixedAsset,
  disposeFixedAsset,
  fetchDepreciationReport,
  fetchDepreciationSchedule,
  fetchFixedAsset,
  fetchFixedAssetRegister,
  fetchFixedAssets,
  updateFixedAsset,
} from "./api";
import type { FixedAssetCreateCashPayload } from "./types";

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

const payload: FixedAssetCreateCashPayload = {
  fixed_asset_category_id: 3,
  name: "Delivery van",
  description: null,
  serial_number: null,
  supplier_reference: null,
  acquisition_date: "2026-01-01",
  depreciation_start_date: "2026-01-01",
  original_cost: "30000.0000",
  salvage_value: null,
  acquisition_account_id: 1,
};

describe("fixed asset reads", () => {
  it("lists through the proxy and omits empty filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchFixedAssets({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/fixed-assets");
  });

  it("maps list filters to the verified query string", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchFixedAssets({
      status: "DRAFT",
      acquisition_method: "CASH",
      fixed_asset_category_id: 3,
      from: "2026-01-01",
      to: "2026-01-31",
      search: "van",
      page: 2,
      per_page: 50,
    });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/accounting/fixed-assets?");
    expect(url).toContain("status=DRAFT");
    expect(url).toContain("acquisition_method=CASH");
    expect(url).toContain("fixed_asset_category_id=3");
    expect(url).toContain("from=2026-01-01");
    expect(url).toContain("to=2026-01-31");
    expect(url).toContain("search=van");
    expect(url).toContain("page=2");
    expect(url).toContain("per_page=50");
  });

  it("targets the register endpoint", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchFixedAssetRegister({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/fixed-assets/register");
  });

  it("shows one asset and passes an abort signal", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 7 } });
    const controller = new AbortController();

    await fetchFixedAsset(7, controller.signal);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/fixed-assets/7");
    expect(options.signal).toBe(controller.signal);
  });

  it("fetches the depreciation schedule", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchDepreciationSchedule(7);

    expect(firstRequest(fetchMock).url).toBe(
      "/api/backend/accounting/fixed-assets/7/depreciation-schedule",
    );
  });

  it("runs the depreciation report with from/to and category", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchDepreciationReport("2026-01-01", "2026-01-31", 3);

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/accounting/fixed-assets/depreciation-report?");
    expect(url).toContain("from=2026-01-01");
    expect(url).toContain("to=2026-01-31");
    expect(url).toContain("fixed_asset_category_id=3");
  });
});

describe("fixed asset writes", () => {
  it("creates a cash asset with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 7 } });

    await createFixedAssetCash(payload);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/fixed-assets/cash");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body)).name).toBe("Delivery van");
  });

  it("omits a null salvage_value so the server defaults it", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 7 } });

    await createFixedAssetCash(payload);

    const body = JSON.parse(String(firstRequest(fetchMock).options.body)) as Record<string, unknown>;
    expect(body).not.toHaveProperty("salvage_value");
  });

  it("creates a supplier-credit asset on the supplier-credit route", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 7 } });

    await createFixedAssetSupplierCredit(payload);

    expect(firstRequest(fetchMock).url).toBe(
      "/api/backend/accounting/fixed-assets/supplier-credit",
    );
  });

  it("updates with a PUT body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 7 } });

    await updateFixedAsset(7, { name: "Renamed" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/fixed-assets/7");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({ name: "Renamed" });
  });

  it("deletes with DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteFixedAsset(7);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/fixed-assets/7");
    expect(options.method).toBe("DELETE");
  });

  it("capitalises with a bodyless POST", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 7 } });

    await capitalizeFixedAsset(7);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/fixed-assets/7/capitalize");
    expect(options.method).toBe("POST");
    expect(options.body).toBeUndefined();
  });

  it("depreciates without an as_of date by sending an empty body", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await depreciateFixedAsset(7);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/fixed-assets/7/depreciation");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({});
  });

  it("depreciates with an as_of date", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await depreciateFixedAsset(7, "2026-01-31");

    const body = JSON.parse(String(firstRequest(fetchMock).options.body)) as Record<string, unknown>;
    expect(body).toEqual({ as_of: "2026-01-31" });
  });

  it("disposes with the disposal payload", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await disposeFixedAsset(7, {
      disposal_date: "2026-06-01",
      proceeds: "12000.0000",
      reason: "Sold",
      proceeds_account_id: 1,
    });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/fixed-assets/7/dispose");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body)).proceeds).toBe("12000.0000");
  });
});
