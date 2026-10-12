import { renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useAccountOptions } from "./use-account-options";

const { fetchAccounts } = vi.hoisted(() => ({
  fetchAccounts: vi.fn(),
}));

vi.mock("@/lib/accounts/api", () => ({
  fetchAccounts,
}));

const accountRow = (id: number, code: string) => ({
  id,
  code,
  name: `Account ${code}`,
  account_type: "ASSET",
  cash_bank_kind: null,
  is_cash_bank_account: false,
  normal_balance: "DEBIT",
  is_contra: false,
  parent_id: null,
  description: null,
  is_active: true,
  is_system: false,
  has_journal_history: false,
  created_at: null,
  updated_at: null,
});

beforeEach(() => {
  fetchAccounts.mockReset();
});

describe("useAccountOptions", () => {
  it("walks the pages until a short page ends the sequence", async () => {
    const page1 = Array.from({ length: 100 }, (_, index) => accountRow(index + 1, String(5000 + index)));
    const page2 = [accountRow(101, "1000")];

    fetchAccounts.mockImplementation((query: { page?: number }) =>
      Promise.resolve(query.page === 2 ? page2 : page1),
    );

    const { result } = renderHook(() => useAccountOptions(1, true));

    expect(result.current.status).toBe("loading");

    await waitFor(() => expect(result.current.status).toBe("ready"));
    expect(fetchAccounts).toHaveBeenCalledTimes(2);
    expect(result.current.items).toHaveLength(101);
    expect(result.current.items[0].code).toBe("1000");
  });

  it("stops after a single short page", async () => {
    fetchAccounts.mockResolvedValue([accountRow(1, "1000")]);

    const { result } = renderHook(() => useAccountOptions(1, true));

    await waitFor(() => expect(result.current.status).toBe("ready"));
    expect(fetchAccounts).toHaveBeenCalledTimes(1);
    expect(result.current.items).toHaveLength(1);
  });

  it("makes no request when disabled", () => {
    const { result } = renderHook(() => useAccountOptions(1, false));

    expect(result.current.status).toBe("error");
    expect(fetchAccounts).not.toHaveBeenCalled();
  });

  it("makes no request when no company is selected", () => {
    const { result } = renderHook(() => useAccountOptions(null, true));

    expect(result.current.status).toBe("error");
    expect(fetchAccounts).not.toHaveBeenCalled();
  });

  it("surfaces an error when the request rejects", async () => {
    fetchAccounts.mockRejectedValue(new Error("network"));

    const { result } = renderHook(() => useAccountOptions(1, true));

    await waitFor(() => expect(result.current.status).toBe("error"));
  });
});
