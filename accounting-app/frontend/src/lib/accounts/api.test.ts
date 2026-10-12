import { afterEach, describe, expect, it, vi } from "vitest";

import {
  activateAccount,
  createAccount,
  deactivateAccount,
  deleteAccount,
  fetchAccountTypes,
  fetchAccounts,
  updateAccount,
} from "./api";
import type { AccountWritePayload } from "./types";

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

const payload: AccountWritePayload = {
  code: "1000",
  name: "Cash",
  account_type: "ASSET",
  cash_bank_kind: null,
  normal_balance: null,
  description: null,
};

describe("fetchAccounts", () => {
  it("routes through the proxy and omits empty filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchAccounts({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounts");
  });

  it("maps filters to the verified query string", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchAccounts({
      search: "cash",
      account_type: "ASSET",
      is_active: false,
      page: 2,
      per_page: 50,
    });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/accounts?");
    expect(url).toContain("search=cash");
    expect(url).toContain("account_type=ASSET");
    expect(url).toContain("is_active=0");
    expect(url).toContain("page=2");
    expect(url).toContain("per_page=50");
  });

  it("encodes an active filter as 1, not true", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchAccounts({ is_active: true });

    expect(firstRequest(fetchMock).url).toContain("is_active=1");
  });

  it("passes an abort signal to fetch", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });
    const controller = new AbortController();

    await fetchAccounts({}, controller.signal);

    expect(firstRequest(fetchMock).options.signal).toBe(controller.signal);
  });
});

describe("fetchAccountTypes", () => {
  it("requests the types endpoint", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchAccountTypes();

    expect(firstRequest(fetchMock).url).toBe("/api/backend/accounts/types");
  });
});

describe("account writes", () => {
  it("creates an account with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 1 } });

    await createAccount(payload);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounts");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual(payload);
  });

  it("updates an account with a PUT body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 1 } });

    await updateAccount(1, { name: "Cash on Hand" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounts/1");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({ name: "Cash on Hand" });
  });

  it("deletes an account with DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteAccount(9);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounts/9");
    expect(options.method).toBe("DELETE");
  });

  it("activates and deactivates via POST sub-resources", async () => {
    const activateMock = mockJson(200, { success: true, data: { id: 7 } });
    await activateAccount(7);
    expect(firstRequest(activateMock).url).toBe("/api/backend/accounts/7/activate");
    expect(firstRequest(activateMock).options.method).toBe("POST");

    const deactivateMock = mockJson(200, { success: true, data: { id: 7 } });
    await deactivateAccount(7);
    expect(firstRequest(deactivateMock).url).toBe(
      "/api/backend/accounts/7/deactivate",
    );
    expect(firstRequest(deactivateMock).options.method).toBe("POST");
  });
});