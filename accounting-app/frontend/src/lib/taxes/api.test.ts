import { afterEach, describe, expect, it, vi } from "vitest";

import {
  activateTax,
  activateTaxRate,
  calculateTax,
  createTax,
  createTaxRate,
  deactivateTax,
  deactivateTaxRate,
  deleteTax,
  deleteTaxRate,
  fetchTax,
  fetchTaxAccountMapping,
  fetchTaxByTaxReport,
  fetchTaxRates,
  fetchTaxSummaryReport,
  fetchTaxes,
  updateTax,
  updateTaxAccountMapping,
  updateTaxRate,
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

describe("tax list reads", () => {
  it("routes through the proxy and omits the filter when inactive", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchTaxes();

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/taxes");
  });

  it("maps active_only to the verified query string", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchTaxes({ active_only: true });

    expect(firstRequest(fetchMock).url).toBe(
      "/api/backend/accounting/taxes?active_only=1",
    );
  });

  it("passes an abort signal to fetch", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });
    const controller = new AbortController();

    await fetchTaxes({}, controller.signal);

    expect(firstRequest(fetchMock).options.signal).toBe(controller.signal);
  });

  it("fetches one tax", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await fetchTax(9);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/taxes/9");
  });

  it("fetches a tax's rate history", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchTaxRates(9);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/taxes/9/rates");
  });

  it("fetches the account mapping", async () => {
    const fetchMock = mockJson(200, { success: true, data: { tax_id: 9 } });

    await fetchTaxAccountMapping(9);

    expect(firstRequest(fetchMock).url).toBe(
      "/api/backend/accounting/taxes/9/account-mapping",
    );
  });
});

describe("tax writes", () => {
  it("creates a tax with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 1 } });

    await createTax({
      code: "VAT",
      name: "VAT",
      description: null,
      tax_type: "OUTPUT",
      calculation_basis: "EXCLUSIVE",
    });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/taxes");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toMatchObject({ code: "VAT" });
  });

  it("updates a tax with a PUT body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await updateTax(9, { name: "VAT standard" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/taxes/9");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({ name: "VAT standard" });
  });

  it("deletes a tax", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteTax(9);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/taxes/9");
    expect(options.method).toBe("DELETE");
  });

  it("activates and deactivates a tax via bodyless POST sub-resources", async () => {
    const activateMock = mockJson(200, { success: true, data: { id: 9 } });
    await activateTax(9);
    expect(firstRequest(activateMock).url).toBe("/api/backend/accounting/taxes/9/activate");
    expect(firstRequest(activateMock).options.method).toBe("POST");

    const deactivateMock = mockJson(200, { success: true, data: { id: 9 } });
    await deactivateTax(9);
    expect(firstRequest(deactivateMock).url).toBe("/api/backend/accounting/taxes/9/deactivate");
    expect(firstRequest(deactivateMock).options.method).toBe("POST");
  });
});

describe("tax rate writes", () => {
  it("creates a rate with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 5 } });

    await createTaxRate(9, { rate: "20.0000", effective_from: "2026-01-01", effective_to: null });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/taxes/9/rates");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({
      rate: "20.0000",
      effective_from: "2026-01-01",
      effective_to: null,
    });
  });

  it("updates and deletes a rate", async () => {
    const updateMock = mockJson(200, { success: true, data: { id: 5 } });
    await updateTaxRate(9, 5, { rate: "21.0000" });
    expect(firstRequest(updateMock).url).toBe("/api/backend/accounting/taxes/9/rates/5");
    expect(firstRequest(updateMock).options.method).toBe("PUT");

    const deleteMock = mockJson(200, { success: true });
    await deleteTaxRate(9, 5);
    expect(firstRequest(deleteMock).url).toBe("/api/backend/accounting/taxes/9/rates/5");
    expect(firstRequest(deleteMock).options.method).toBe("DELETE");
  });

  it("activates and deactivates a rate", async () => {
    const activateMock = mockJson(200, { success: true, data: { id: 5 } });
    await activateTaxRate(9, 5);
    expect(firstRequest(activateMock).url).toBe(
      "/api/backend/accounting/taxes/9/rates/5/activate",
    );

    const deactivateMock = mockJson(200, { success: true, data: { id: 5 } });
    await deactivateTaxRate(9, 5);
    expect(firstRequest(deactivateMock).url).toBe(
      "/api/backend/accounting/taxes/9/rates/5/deactivate",
    );
  });
});

describe("tax account mapping write", () => {
  it("PUTs a full replacement body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { tax_id: 9 } });

    await updateTaxAccountMapping(9, { output_account_id: 30, input_account_id: null });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/taxes/9/account-mapping");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({
      output_account_id: 30,
      input_account_id: null,
    });
  });
});

describe("tax calculation", () => {
  it("posts to the singular calculate route", async () => {
    const fetchMock = mockJson(200, { success: true, data: { basis: "EXCLUSIVE" } });

    await calculateTax({ amount: "100.00", date: "2026-04-01", tax_ids: [1] });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/tax/calculate");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({
      amount: "100.00",
      date: "2026-04-01",
      tax_ids: [1],
    });
  });
});

describe("tax reports", () => {
  it("fetches the summary report", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchTaxSummaryReport({ from: "2026-04-01", to: "2026-04-30" });

    expect(firstRequest(fetchMock).url).toBe(
      "/api/backend/accounting/tax-reports/summary?from=2026-04-01&to=2026-04-30",
    );
  });

  it("fetches the by-tax report and omits empty bounds", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchTaxByTaxReport();

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounting/tax-reports/by-tax");
  });
});
