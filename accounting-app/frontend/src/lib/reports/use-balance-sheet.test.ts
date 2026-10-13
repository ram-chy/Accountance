import { renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";

import { DEFAULT_BALANCE_SHEET_FILTERS, type BalanceSheetFilters } from "./balance-sheet-types";
import { useBalanceSheet } from "./use-balance-sheet";

const { fetchBalanceSheet } = vi.hoisted(() => ({
  fetchBalanceSheet: vi.fn(),
}));

vi.mock("./api", () => ({
  fetchBalanceSheet,
}));

const sheet = {
  period: { from: null, to: "2026-12-31" },
  base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
  assets: { total: "1000.0000", accounts: [{ account_id: 1, code: "1000", name: "Cash", amount: "1000.0000" }] },
  liabilities: { total: "0.0000", accounts: [] },
  equity: {
    total: "1000.0000",
    accounts: [{ account_id: 2, code: "3000", name: "Capital", amount: "1000.0000" }],
    retained_earnings: "0.0000",
  },
  totals: {
    assets: "1000.0000",
    liabilities: "0.0000",
    equity: "1000.0000",
    liabilities_and_equity: "1000.0000",
    is_balanced: true,
    difference: "0.0000",
  },
  current_period_result: "0.0000",
};

const filters: BalanceSheetFilters = { ...DEFAULT_BALANCE_SHEET_FILTERS };

function renderSheet(companyId: number | null, enabled = true) {
  return renderHook(
    ({ id }: { id: number | null }) =>
      useBalanceSheet({ companyId: id, enabled, filters, reloadToken: 0 }),
    { initialProps: { id: companyId } },
  );
}

beforeEach(() => {
  fetchBalanceSheet.mockReset();
});

describe("useBalanceSheet", () => {
  it("loads and validates the balance sheet for the active company", async () => {
    fetchBalanceSheet.mockResolvedValue(sheet);

    const { result } = renderSheet(1);

    expect(result.current.status).toBe("loading");

    await waitFor(() => expect(result.current.status).toBe("ready"));
    expect(result.current.status === "ready" && result.current.data.totals.is_balanced).toBe(true);
  });

  it("reports unavailable without requesting when the role cannot view reports", () => {
    const { result } = renderSheet(1, false);

    expect(result.current.status).toBe("unavailable");
    expect(fetchBalanceSheet).not.toHaveBeenCalled();
  });

  it("reports unavailable without requesting when no company is selected", () => {
    const { result } = renderSheet(null);

    expect(result.current.status).toBe("unavailable");
    expect(fetchBalanceSheet).not.toHaveBeenCalled();
  });

  it("maps a 403 to an unavailable state", async () => {
    fetchBalanceSheet.mockRejectedValue(new ApiError({ status: 403, message: "Forbidden" }));

    const { result } = renderSheet(1);

    await waitFor(() => expect(result.current.status).toBe("unavailable"));
  });

  it("surfaces an invalid payload as an error", async () => {
    fetchBalanceSheet.mockResolvedValue({ ...sheet, current_period_result: undefined });

    const { result } = renderSheet(1);

    await waitFor(() => expect(result.current.status).toBe("error"));
  });
});
