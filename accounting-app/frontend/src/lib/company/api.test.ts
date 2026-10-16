import { afterEach, describe, expect, it, vi } from "vitest";

import {
  activateCompany,
  addCompanyMember,
  createCompany,
  deactivateCompany,
  fetchCompany,
  fetchCompanySettings,
  removeCompanyMember,
  updateCompany,
  updateCompanySettings,
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

describe("company settings", () => {
  it("reads settings through the company-scoped proxy path", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 1 } });

    await fetchCompanySettings();

    expect(firstRequest(fetchMock).url).toBe("/api/backend/company/settings");
  });

  it("writes settings with a PUT body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 1 } });

    await updateCompanySettings({ invoice_number_prefix: "INV", default_payment_terms_days: 30 });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/company/settings");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({
      invoice_number_prefix: "INV",
      default_payment_terms_days: 30,
    });
  });
});

describe("company profile", () => {
  it("fetches one company from the global companies family", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await fetchCompany(9);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/companies/9");
  });

  it("updates a company with a PUT body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await updateCompany(9, { name: "Acme" } as never);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/companies/9");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({ name: "Acme" });
  });

  it("creates a company with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 10 } });

    await createCompany({ name: "New Co", is_default: true } as never);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/companies");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({ name: "New Co", is_default: true });
  });
});

describe("company lifecycle", () => {
  it("deactivates via a bodyless POST sub-resource", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await deactivateCompany(9);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/companies/9/deactivate");
    expect(options.method).toBe("POST");
  });

  it("activates via a bodyless POST sub-resource", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await activateCompany(9);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/companies/9/activate");
    expect(options.method).toBe("POST");
  });
});

describe("company members", () => {
  it("adds a member by user id", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 9 } });

    await addCompanyMember(9, 42);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/companies/9/members");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual({ user_id: 42 });
  });

  it("removes a member with a DELETE", async () => {
    const fetchMock = mockJson(200, { success: true, data: null });

    await removeCompanyMember(9, 42);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/companies/9/members/42");
    expect(options.method).toBe("DELETE");
  });
});
