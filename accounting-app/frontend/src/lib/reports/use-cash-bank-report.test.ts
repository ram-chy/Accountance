import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";

import { DEFAULT_CASH_BANK_FILTERS, type CashBankFilters } from "./cash-bank-report-types";
import { useCashBankReport } from "./use-cash-bank-report";

const { fetchCashBank } = vi.hoisted(() => ({
  fetchCashBank: vi.fn(),
}));

vi.mock("./api", () => ({
  fetchCashBank,
}));

const report = {
  account: {
    id: 9,
    code: "1010",
    name: "Bank",
    account_type: "ASSET",
    normal_balance: "DEBIT",
    is_active: true,
  },
  period: { from: null, to: null },
  base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
  opening_balance: "200.0000",
  rows: [
    {
      journal_id: 5,
      journal_line_id: 50,
      journal_number: "JNL-000005",
      journal_date: "2026-02-10",
      reference: null,
      description: "Deposit",
      source_type: "CUSTOMER_RECEIPT",
      source_id: 3,
      debit: "100.0000",
      credit: "0.0000",
      running_balance: "300.0000",
      currency_id: null,
      exchange_rate: null,
      foreign_debit: null,
      foreign_credit: null,
    },
  ],
  period_debits: "100.0000",
  period_credits: "0.0000",
  closing_balance: "300.0000",
  closing_balance_signed: "300.0000",
};

function filtersFor(accountId: number | null): CashBankFilters {
  return { ...DEFAULT_CASH_BANK_FILTERS, account_id: accountId };
}

function renderReport(companyId: number | null, accountId: number | null, enabled = true) {
  return renderHook(
    ({ id, account }: { id: number | null; account: number | null }) =>
      useCashBankReport({
        companyId: id,
        enabled,
        filters: filtersFor(account),
        reloadToken: 0,
      }),
    { initialProps: { id: companyId, account: accountId } },
  );
}

beforeEach(() => {
  fetchCashBank.mockReset();
});

describe("useCashBankReport", () => {
  it("loads and validates the report once an account is chosen", async () => {
    fetchCashBank.mockResolvedValue(report);

    const { result } = renderReport(1, 9);

    expect(result.current.status).toBe("loading");

    await waitFor(() => expect(result.current.status).toBe("ready"));
    expect(result.current.status === "ready" && result.current.data.closing_balance).toBe("300.0000");
    expect(fetchCashBank).toHaveBeenCalledTimes(1);
  });

  it("does not request until an account is selected", () => {
    const { result } = renderReport(1, null);

    expect(result.current.status).toBe("loading");
    expect(fetchCashBank).not.toHaveBeenCalled();
  });

  it("reports unavailable without requesting when the role cannot view reports", () => {
    const { result } = renderReport(1, 9, false);

    expect(result.current.status).toBe("unavailable");
    expect(fetchCashBank).not.toHaveBeenCalled();
  });

  it("reports unavailable without requesting when no company is selected", () => {
    const { result } = renderReport(null, 9);

    expect(result.current.status).toBe("unavailable");
    expect(fetchCashBank).not.toHaveBeenCalled();
  });

  it("maps a 403 to an unavailable state", async () => {
    fetchCashBank.mockRejectedValue(new ApiError({ status: 403, message: "Forbidden" }));

    const { result } = renderReport(1, 9);

    await waitFor(() => expect(result.current.status).toBe("unavailable"));
  });

  it("surfaces an invalid payload as an error rather than bad data", async () => {
    fetchCashBank.mockResolvedValue({ ...report, opening_balance: "oops" });

    const { result } = renderReport(1, 9);

    await waitFor(() => expect(result.current.status).toBe("error"));
  });

  it("aborts the previous account request and never shows its rows", async () => {
    let firstResolve!: (value: unknown) => void;
    const firstSignal: { current?: AbortSignal } = {};

    fetchCashBank.mockImplementationOnce((_filters: unknown, signal: AbortSignal) => {
      firstSignal.current = signal;
      return new Promise((resolve) => {
        firstResolve = resolve;
      });
    });
    fetchCashBank.mockImplementationOnce(() => Promise.resolve(report));

    const { result, rerender } = renderReport(1, 9);

    rerender({ id: 1, account: 10 });

    expect(firstSignal.current?.aborted).toBe(true);

    await act(async () => {
      firstResolve(report);
    });

    await waitFor(() => expect(result.current.status).toBe("ready"));
  });
});
