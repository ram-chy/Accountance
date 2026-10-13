import { renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";

import { DEFAULT_RECEIVABLES_AGING_FILTERS, type ReceivablesAgingFilters } from "./receivables-aging-types";
import { useReceivablesAging } from "./use-receivables-aging";

const { fetchReceivablesAging } = vi.hoisted(() => ({
  fetchReceivablesAging: vi.fn(),
}));

vi.mock("./api", () => ({
  fetchReceivablesAging,
}));

const report = {
  as_of: "2026-06-30",
  base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
  buckets: [
    { key: "current", label: "Current (0-30)", min: null, max: 30, total: "100.0000" },
    { key: "days_31_60", label: "31-60 days", min: 31, max: 60, total: "600.0000" },
  ],
  rows: [
    {
      counterparty: { id: 4, code: "C-001", name: "Acme" },
      buckets: { current: "100.0000", days_31_60: "600.0000" },
      total: "700.0000",
    },
  ],
  totals: {
    by_bucket: { current: "100.0000", days_31_60: "600.0000" },
    total: "700.0000",
  },
};

function renderReport(companyId: number | null, filters: ReceivablesAgingFilters, enabled = true) {
  return renderHook(
    ({ id, f }: { id: number | null; f: ReceivablesAgingFilters }) =>
      useReceivablesAging({ companyId: id, enabled, filters: f, reloadToken: 0 }),
    { initialProps: { id: companyId, f: filters } },
  );
}

beforeEach(() => {
  fetchReceivablesAging.mockReset();
});

describe("useReceivablesAging", () => {
  it("loads the aged report for the active company", async () => {
    fetchReceivablesAging.mockResolvedValue(report);

    const { result } = renderReport(1, { ...DEFAULT_RECEIVABLES_AGING_FILTERS });

    await waitFor(() => expect(result.current.status).toBe("ready"));
    expect(result.current.status === "ready" && result.current.data.buckets).toHaveLength(2);
  });

  it("does not request when the role cannot view reports", () => {
    const { result } = renderReport(1, { ...DEFAULT_RECEIVABLES_AGING_FILTERS }, false);

    expect(result.current.status).toBe("unavailable");
    expect(fetchReceivablesAging).not.toHaveBeenCalled();
  });

  it("maps a 403 to unavailable", async () => {
    fetchReceivablesAging.mockRejectedValue(new ApiError({ status: 403, message: "Forbidden" }));

    const { result } = renderReport(1, { ...DEFAULT_RECEIVABLES_AGING_FILTERS });

    await waitFor(() => expect(result.current.status).toBe("unavailable"));
  });

  it("surfaces an invalid payload as an error", async () => {
    fetchReceivablesAging.mockResolvedValue({ ...report, buckets: [{ key: "current" }] });

    const { result } = renderReport(1, { ...DEFAULT_RECEIVABLES_AGING_FILTERS });

    await waitFor(() => expect(result.current.status).toBe("error"));
  });
});
