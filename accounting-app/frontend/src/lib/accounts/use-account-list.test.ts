import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DEFAULT_ACCOUNT_FILTERS, type AccountListFilters } from "./types";
import { useAccountList } from "./use-account-list";

type PendingCall = {
  query: { companyScoped?: unknown; page?: number };
  signal: AbortSignal;
  resolve: (value: unknown) => void;
};

const { fetchAccounts, fetchAccountTypes } = vi.hoisted(() => ({
  fetchAccounts: vi.fn(),
  fetchAccountTypes: vi.fn(),
}));

vi.mock("./api", () => ({
  fetchAccounts,
  fetchAccountTypes,
}));

const accountRow = (id: number, name: string) => ({
  id,
  code: String(1000 + id),
  name,
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

function pending(): PendingCall {
  let resolve!: (value: unknown) => void;

  const call: Partial<PendingCall> = {
    resolve: (value: unknown) => resolve(value),
  };

  fetchAccounts.mockImplementationOnce(
    (query: PendingCall["query"], signal: AbortSignal) => {
      call.query = query;
      call.signal = signal;
      return new Promise((res) => {
        resolve = res;
      });
    },
  );

  return call as PendingCall;
}

const filters: AccountListFilters = { ...DEFAULT_ACCOUNT_FILTERS };

function renderList(companyId: number | null, enabled = true) {
  return renderHook(
    ({ id }: { id: number | null }) =>
      useAccountList({ companyId: id, enabled, filters, page: 1, reloadToken: 0 }),
    { initialProps: { id: companyId } },
  );
}

beforeEach(() => {
  fetchAccounts.mockReset();
  fetchAccountTypes.mockReset();
  fetchAccountTypes.mockResolvedValue([]);
});

describe("useAccountList", () => {
  it("loads and validates the account page for the active company", async () => {
    fetchAccounts.mockResolvedValue([accountRow(1, "Cash")]);

    const { result } = renderList(1);

    expect(result.current.status).toBe("loading");

    await waitFor(() => expect(result.current.status).toBe("ready"));
    expect(result.current.items.map((item) => item.name)).toEqual(["Cash"]);
    expect(fetchAccounts).toHaveBeenCalledTimes(1);
  });

  it("reports unavailable without requesting when the role cannot view", () => {
    const { result } = renderList(1, false);

    expect(result.current.status).toBe("unavailable");
    expect(fetchAccounts).not.toHaveBeenCalled();
  });

  it("reports unavailable without requesting when no company is selected", () => {
    const { result } = renderList(null);

    expect(result.current.status).toBe("unavailable");
    expect(fetchAccounts).not.toHaveBeenCalled();
  });

  it("aborts the previous company request and never shows its rows", async () => {
    const first = pending();
    const second = pending();

    const { result, rerender } = renderList(1);

    expect(result.current.status).toBe("loading");

    rerender({ id: 2 });

    expect(first.signal.aborted).toBe(true);
    expect(second.signal.aborted).toBe(false);

    await act(async () => {
      first.resolve([accountRow(99, "Company A only")]);
    });

    expect(result.current.status).toBe("loading");

    await act(async () => {
      second.resolve([accountRow(1, "Company B only")]);
    });

    await waitFor(() => expect(result.current.status).toBe("ready"));
    expect(result.current.items.map((item) => item.name)).toEqual([
      "Company B only",
    ]);
  });

  it("maps a 403 into an unavailable state", async () => {
    const { ApiError } = await import("@/lib/api/errors");
    fetchAccounts.mockRejectedValue(new ApiError({ status: 403, message: "nope" }));

    const { result } = renderList(1);

    await waitFor(() => expect(result.current.status).toBe("unavailable"));
  });

  it("maps a server failure into an error state with the message", async () => {
    const { ApiError } = await import("@/lib/api/errors");
    fetchAccounts.mockRejectedValue(
      new ApiError({ status: 500, message: "Server exploded." }),
    );

    const { result } = renderList(1);

    await waitFor(() => expect(result.current.status).toBe("error"));
    expect(result.current.message).toBe("Server exploded.");
  });
});