import { renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";

import { DEFAULT_RECEIVABLES_FILTERS, type ReceivablesFilters } from "./receivables-types";
import { useReceivables } from "./use-receivables";

const { fetchReceivables } = vi.hoisted(() => ({
  fetchReceivables: vi.fn(),
}));

vi.mock("./api", () => ({
  fetchReceivables,
}));

const report = {
  as_of: "2026-06-30",
  base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
  rows: [
    {
      invoice_id: 11,
      invoice_number: "INV-011",
      invoice_date: "2026-05-01",
      due_date: "2026-05-31",
      customer: { id: 4, code: "C-001", name: "Acme" },
      grand_total: "1000.0000",
      paid_total: "400.0000",
      balance_due: "600.0000",
      days_past_due: 30,
      currency_code: null,
      exchange_rate: null,
      base_grand_total: null,
    },
  ],
  count: 1,
  totals: { grand_total: "1000.0000", paid_total: "400.0000", balance_due: "600.0000" },
};

function renderReport(companyId: number | null, filters: ReceivablesFilters, enabled = true) {
  return renderHook(
    ({ id, f }: { id: number | null; f: ReceivablesFilters }) =>
      useReceivables({ companyId: id, enabled, filters: f, reloadToken: 0 }),
    { initialProps: { id: companyId, f: filters } },
  );
}

beforeEach(() => {
  fetchReceivables.mockReset();
});

describe("useReceivables", () => {
  it("loads the report for the active company", async () => {
    fetchReceivables.mockResolvedValue(report);

    const { result } = renderReport(1, { ...DEFAULT_RECEIVABLES_FILTERS });

    await waitFor(() => expect(result.current.status).toBe("ready"));
    expect(result.current.status === "ready" && result.current.data.totals.balance_due).toBe("600.0000");
  });

  it("does not request without a company", () => {
    const { result } = renderReport(null, { ...DEFAULT_RECEIVABLES_FILTERS });

    expect(result.current.status).toBe("unavailable");
    expect(fetchReceivables).not.toHaveBeenCalled();
  });

  it("maps a 403 to unavailable", async () => {
    fetchReceivables.mockRejectedValue(new ApiError({ status: 403, message: "Forbidden" }));

    const { result } = renderReport(1, { ...DEFAULT_RECEIVABLES_FILTERS });

    await waitFor(() => expect(result.current.status).toBe("unavailable"));
  });

  it("surfaces an invalid payload as an error", async () => {
    fetchReceivables.mockResolvedValue({ ...report, totals: undefined });

    const { result } = renderReport(1, { ...DEFAULT_RECEIVABLES_FILTERS });

    await waitFor(() => expect(result.current.status).toBe("error"));
  });
});
