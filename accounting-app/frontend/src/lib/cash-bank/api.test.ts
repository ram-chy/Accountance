import { afterEach, describe, expect, it, vi } from "vitest";

import {
  activateBankDetails,
  createCashBankTransaction,
  deactivateBankDetails,
  deleteBankDetails,
  deleteCashBankTransaction,
  fetchAccountBalance,
  fetchCashBankAccounts,
  fetchCashBankTransaction,
  fetchCashBankTransactions,
  postCashBankTransaction,
  updateCashBankAccount,
  updateCashBankTransaction,
} from "./api";
import type { CashBankTransactionWritePayload } from "./types";

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

const payload: CashBankTransactionWritePayload = {
  transaction_date: "2027-02-01",
  amount: "500",
  source_account_id: 20,
  destination_account_id: 1,
  reference: "REF-1",
  notes: null,
};

describe("fetchCashBankAccounts", () => {
  it("routes through the proxy and omits an empty kind", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchCashBankAccounts({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/cash-bank-accounts");
  });

  it("maps the kind filter and page size", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchCashBankAccounts({ cash_bank_kind: "BANK", per_page: 100 });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/cash-bank-accounts?");
    expect(url).toContain("cash_bank_kind=BANK");
    expect(url).toContain("per_page=100");
  });
});

describe("fetchCashBankTransactions", () => {
  it("maps every filter to the verified query string", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchCashBankTransactions({
      transaction_type: "TRANSFER",
      status: "POSTED",
      account_id: 7,
      from: "2027-02-01",
      to: "2027-02-28",
      reference: "REF",
      page: 2,
      per_page: 25,
    });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/cash-bank-transactions?");
    expect(url).toContain("transaction_type=TRANSFER");
    expect(url).toContain("status=POSTED");
    expect(url).toContain("account_id=7");
    expect(url).toContain("from=2027-02-01");
    expect(url).toContain("to=2027-02-28");
    expect(url).toContain("reference=REF");
    expect(url).toContain("page=2");
    expect(url).toContain("per_page=25");
  });

  it("passes an abort signal to fetch", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });
    const controller = new AbortController();

    await fetchCashBankTransactions({}, controller.signal);

    expect(firstRequest(fetchMock).options.signal).toBe(controller.signal);
  });
});

describe("cash bank account writes", () => {
  it("updates classification with a PUT body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 1 } });

    await updateCashBankAccount(1, { cash_bank_kind: "BANK" });

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/cash-bank-accounts/1");
    expect(options.method).toBe("PUT");
    expect(JSON.parse(String(options.body))).toEqual({ cash_bank_kind: "BANK" });
  });

  it("removes bank details with DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteBankDetails(3);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/cash-bank-accounts/3/bank-details");
    expect(options.method).toBe("DELETE");
  });

  it("activates via the activate sub-resource", async () => {
    const fetchMock = mockJson(200, { success: true });

    await activateBankDetails(3);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/cash-bank-accounts/3/activate");
    expect(options.method).toBe("POST");
  });

  it("deactivates via the deactivate sub-resource", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deactivateBankDetails(3);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/cash-bank-accounts/3/deactivate");
    expect(options.method).toBe("POST");
  });
});

describe("cash bank transaction writes", () => {
  it("reads one transaction", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 3 } });

    await fetchCashBankTransaction(3);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/cash-bank-transactions/3");
    expect(options.method).toBe("GET");
  });

  it("creates a deposit at the deposits endpoint", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 1 } });

    await createCashBankTransaction("DEPOSIT", payload);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/cash-bank-transactions/deposits");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual(payload);
  });

  it("creates a withdrawal at the withdrawals endpoint", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 1 } });

    await createCashBankTransaction("WITHDRAWAL", payload);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/cash-bank-transactions/withdrawals");
  });

  it("creates a transfer at the transfers endpoint", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 1 } });

    await createCashBankTransaction("TRANSFER", payload);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/cash-bank-transactions/transfers");
  });

  it("updates a transaction with a PUT body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 1 } });

    await updateCashBankTransaction(1, payload);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/cash-bank-transactions/1");
    expect(options.method).toBe("PUT");
  });

  it("deletes a transaction with DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteCashBankTransaction(9);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/cash-bank-transactions/9");
    expect(options.method).toBe("DELETE");
  });

  it("posts a transaction via the post sub-resource", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 7 } });

    await postCashBankTransaction(7);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/cash-bank-transactions/7/post");
    expect(options.method).toBe("POST");
  });
});

describe("fetchAccountBalance", () => {
  it("reads a balance through the accounting route", async () => {
    const fetchMock = mockJson(200, { success: true, data: { account_id: 1 } });

    await fetchAccountBalance(1);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/accounting/accounts/1/balance");
    expect(options.method).toBe("GET");
  });
});
