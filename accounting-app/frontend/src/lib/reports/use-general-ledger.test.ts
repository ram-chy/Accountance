import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";

import { DEFAULT_GENERAL_LEDGER_FILTERS, type GeneralLedgerFilters } from "./general-ledger-types";
import { useGeneralLedger } from "./use-general-ledger";

const { fetchGeneralLedger } = vi.hoisted(() => ({
  fetchGeneralLedger: vi.fn(),
}));

vi.mock("./api", () => ({
  fetchGeneralLedger,
}));

const ledger = {
  account: {
    id: 1,
    code: "1000",
    name: "Cash",
    account_type: "ASSET",
    normal_balance: "DEBIT",
    is_active: true,
  },
  period: { from: null, to: null },
  base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
  opening_balance: "0.0000",
  rows: [
    {
      journal_id: 1,
      journal_line_id: 10,
      journal_number: "JNL-000001",
      journal_date: "2026-01-15",
      reference: null,
      description: "Opening entry",
      source_type: "MANUAL",
      source_id: null,
      debit: "500.0000",
      credit: "0.0000",
      running_balance: "500.0000",
      currency_id: null,
      exchange_rate: null,
      foreign_debit: null,
      foreign_credit: null,
    },
  ],
  period_debits: "500.0000",
  period_credits: "0.0000",
  closing_balance: "500.0000",
  closing_balance_signed: "500.0000",
};

function filtersFor(accountId: number | null): GeneralLedgerFilters {
  return { ...DEFAULT_GENERAL_LEDGER_FILTERS, account_id: accountId };
}

function renderLedger(companyId: number | null, accountId: number | null, enabled = true) {
  return renderHook(
    ({ id, account }: { id: number | null; account: number | null }) =>
      useGeneralLedger({
        companyId: id,
        enabled,
        filters: filtersFor(account),
        reloadToken: 0,
      }),
    { initialProps: { id: companyId, account: accountId } },
  );
}

beforeEach(() => {
  fetchGeneralLedger.mockReset();
});

describe("useGeneralLedger", () => {
  it("loads and validates the ledger once an account is chosen", async () => {
    fetchGeneralLedger.mockResolvedValue(ledger);

    const { result } = renderLedger(1, 1);

    expect(result.current.status).toBe("loading");

    await waitFor(() => expect(result.current.status).toBe("ready"));
    expect(result.current.status === "ready" && result.current.data.rows).toHaveLength(1);
    expect(fetchGeneralLedger).toHaveBeenCalledTimes(1);
  });

  it("does not request until an account is selected", () => {
    const { result } = renderLedger(1, null);

    expect(result.current.status).toBe("loading");
    expect(fetchGeneralLedger).not.toHaveBeenCalled();
  });

  it("reports unavailable without requesting when the role cannot view reports", () => {
    const { result } = renderLedger(1, 1, false);

    expect(result.current.status).toBe("unavailable");
    expect(fetchGeneralLedger).not.toHaveBeenCalled();
  });

  it("reports unavailable without requesting when no company is selected", () => {
    const { result } = renderLedger(null, 1);

    expect(result.current.status).toBe("unavailable");
    expect(fetchGeneralLedger).not.toHaveBeenCalled();
  });

  it("maps a 403 to an unavailable state", async () => {
    fetchGeneralLedger.mockRejectedValue(new ApiError({ status: 403, message: "Forbidden" }));

    const { result } = renderLedger(1, 1);

    await waitFor(() => expect(result.current.status).toBe("unavailable"));
  });

  it("surfaces a validation failure as an error rather than bad data", async () => {
    fetchGeneralLedger.mockResolvedValue({ ...ledger, closing_balance: "oops" });

    const { result } = renderLedger(1, 1);

    await waitFor(() => expect(result.current.status).toBe("error"));
  });

  it("aborts the previous account request and never shows its rows", async () => {
    let firstResolve!: (value: unknown) => void;
    const firstSignal: { current?: AbortSignal } = {};

    fetchGeneralLedger.mockImplementationOnce((_filters: unknown, signal: AbortSignal) => {
      firstSignal.current = signal;
      return new Promise((resolve) => {
        firstResolve = resolve;
      });
    });
    fetchGeneralLedger.mockImplementationOnce(() => Promise.resolve(ledger));

    const { result, rerender } = renderLedger(1, 1);

    rerender({ id: 1, account: 2 });

    expect(firstSignal.current?.aborted).toBe(true);

    await act(async () => {
      firstResolve(ledger);
    });

    await waitFor(() => expect(result.current.status).toBe("ready"));
  });
});
