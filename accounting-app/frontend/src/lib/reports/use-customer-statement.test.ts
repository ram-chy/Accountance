import { renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";

import { DEFAULT_CUSTOMER_STATEMENT_FILTERS, type CustomerStatementFilters } from "./customer-statement-types";
import { useCustomerStatement } from "./use-customer-statement";

const { fetchCustomerStatement } = vi.hoisted(() => ({
  fetchCustomerStatement: vi.fn(),
}));

vi.mock("./api", () => ({
  fetchCustomerStatement,
}));

const statement = {
  customer: { id: 4, code: "C-001", name: "Acme" },
  period: { from: null, to: null },
  base_currency: { code: "USD", name: "US Dollar", symbol: "$", decimals: 2 },
  opening_balance: "0.0000",
  rows: [
    {
      date: "2026-02-01",
      type: "invoice",
      reference: "INV-001",
      description: null,
      debit: "1000.0000",
      credit: "0.0000",
      running_balance: "1000.0000",
      currency_code: null,
      exchange_rate: null,
      foreign_amount: null,
    },
  ],
  totals: { debit: "1000.0000", credit: "0.0000" },
  closing_balance: "1000.0000",
};

function renderStatement(companyId: number | null, filters: CustomerStatementFilters, enabled = true) {
  return renderHook(
    ({ id, f }: { id: number | null; f: CustomerStatementFilters }) =>
      useCustomerStatement({ companyId: id, enabled, filters: f, reloadToken: 0 }),
    { initialProps: { id: companyId, f: filters } },
  );
}

beforeEach(() => {
  fetchCustomerStatement.mockReset();
});

describe("useCustomerStatement", () => {
  it("stays idle without requesting until a customer is chosen", () => {
    const { result } = renderStatement(1, { ...DEFAULT_CUSTOMER_STATEMENT_FILTERS });

    expect(result.current.status).toBe("idle");
    expect(fetchCustomerStatement).not.toHaveBeenCalled();
  });

  it("loads the statement once a customer is selected", async () => {
    fetchCustomerStatement.mockResolvedValue(statement);

    const { result } = renderStatement(1, { customer_id: 4, from: "", to: "" });

    expect(result.current.status).toBe("loading");
    await waitFor(() => expect(result.current.status).toBe("ready"));
    expect(result.current.status === "ready" && result.current.data.closing_balance).toBe("1000.0000");
  });

  it("does not request when the role cannot view reports", () => {
    const { result } = renderStatement(1, { customer_id: 4, from: "", to: "" }, false);

    expect(result.current.status).toBe("unavailable");
    expect(fetchCustomerStatement).not.toHaveBeenCalled();
  });

  it("maps a 403 to unavailable", async () => {
    fetchCustomerStatement.mockRejectedValue(new ApiError({ status: 403, message: "Forbidden" }));

    const { result } = renderStatement(1, { customer_id: 4, from: "", to: "" });

    await waitFor(() => expect(result.current.status).toBe("unavailable"));
  });

  it("surfaces an invalid payload as an error", async () => {
    fetchCustomerStatement.mockResolvedValue({ ...statement, closing_balance: undefined });

    const { result } = renderStatement(1, { customer_id: 4, from: "", to: "" });

    await waitFor(() => expect(result.current.status).toBe("error"));
  });
});
