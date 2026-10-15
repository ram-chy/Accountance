import { afterEach, describe, expect, it, vi } from "vitest";

import {
  activateFixedAssetCategory,
  createFixedAssetCategory,
  deactivateFixedAssetCategory,
  deleteFixedAssetCategory,
  fetchFixedAssetCategories,
  fetchFixedAssetCategory,
  updateFixedAssetCategory,
} from "./api";
import type { FixedAssetCategoryCreatePayload } from "./types";

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

const payload: FixedAssetCategoryCreatePayload = {
  code: "MOTOR",
  name: "Motor vehicles",
  description: null,
  useful_life_months: 60,
  depreciation_method: "STRAIGHT_LINE",
  asset_account_id: 10,
  accumulated_depreciation_account_id: 11,
  depreciation_expense_account_id: 12,
  gain_on_disposal_account_id: null,
  loss_on_disposal_account_id: null,
};

describe("fixed asset category reads", () => {
  it("omits the is_active filter when unset", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchFixedAssetCategories({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/fixed-asset-categories");
  });

  it("maps the active filter to 1/0", async () => {
    const active = mockJson(200, { success: true, data: [] });
    await fetchFixedAssetCategories({ is_active: true });
    expect(firstRequest(active).url).toContain("is_active=1");

    const inactive = mockJson(200, { success: true, data: [] });
    await fetchFixedAssetCategories({ is_active: false });
    expect(firstRequest(inactive).url).toContain("is_active=0");
  });

  it("shows one category", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 3 } });

    await fetchFixedAssetCategory(3);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/fixed-asset-categories/3");
  });
});

describe("fixed asset category writes", () => {
  it("creates with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 3 } });

    await createFixedAssetCategory(payload);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/fixed-asset-categories");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body)).code).toBe("MOTOR");
  });

  it("updates with PUT", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 3 } });

    await updateFixedAssetCategory(3, { name: "Renamed" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/fixed-asset-categories/3");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({ name: "Renamed" });
  });

  it("deletes with DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteFixedAssetCategory(3);

    expect(firstRequest(fetchMock).options.method).toBe("DELETE");
  });

  it("activates with a bodyless POST", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 3 } });

    await activateFixedAssetCategory(3);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/fixed-asset-categories/3/activate");
    expect(options.method).toBe("POST");
    expect(options.body).toBeUndefined();
  });

  it("deactivates with a bodyless POST", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 3 } });

    await deactivateFixedAssetCategory(3);

    expect(firstRequest(fetchMock).url).toBe(
      "/api/backend/accounting/fixed-asset-categories/3/deactivate",
    );
  });
});
