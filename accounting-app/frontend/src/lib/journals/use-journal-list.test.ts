import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DEFAULT_JOURNAL_FILTERS, type JournalListFilters } from "./types";
import { useJournalList } from "./use-journal-list";

type PendingCall = {
  signal: AbortSignal;
  resolve: (value: unknown) => void;
};

const { fetchJournals } = vi.hoisted(() => ({
  fetchJournals: vi.fn(),
}));

vi.mock("./api", () => ({
  fetchJournals,
}));

const journalRow = (id: number, description: string) => ({
  id,
  journal_number: `JNL-00000${id}`,
  journal_date: "2027-01-15",
  description,
  reference: null,
  status: "DRAFT",
  source_type: "MANUAL",
  source_id: null,
  created_by: 1,
  posted_by: null,
  posted_at: null,
  created_at: null,
  updated_at: null,
  lines: [],
  totals: { total_debit: "0.0000", total_credit: "0.0000", is_balanced: true },
});

function pending(): PendingCall {
  let resolve!: (value: unknown) => void;

  const call: Partial<PendingCall> = {
    resolve: (value: unknown) => resolve(value),
  };

  fetchJournals.mockImplementationOnce((_query: unknown, signal: AbortSignal) => {
    call.signal = signal;
    return new Promise((res) => {
      resolve = res;
    });
  });

  return call as PendingCall;
}

const filters: JournalListFilters = { ...DEFAULT_JOURNAL_FILTERS };

function renderList(companyId: number | null, enabled = true) {
  return renderHook(
    ({ id }: { id: number | null }) =>
      useJournalList({ companyId: id, enabled, filters, page: 1, reloadToken: 0 }),
    { initialProps: { id: companyId } },
  );
}

beforeEach(() => {
  fetchJournals.mockReset();
});

describe("useJournalList", () => {
  it("loads and validates the journal page for the active company", async () => {
    fetchJournals.mockResolvedValue([journalRow(1, "Opening")]);

    const { result } = renderList(1);

    expect(result.current.status).toBe("loading");

    await waitFor(() => expect(result.current.status).toBe("ready"));
    expect(result.current.items.map((item) => item.description)).toEqual(["Opening"]);
    expect(fetchJournals).toHaveBeenCalledTimes(1);
  });

  it("reports unavailable without requesting when the role cannot view", () => {
    const { result } = renderList(1, false);

    expect(result.current.status).toBe("unavailable");
    expect(fetchJournals).not.toHaveBeenCalled();
  });

  it("reports unavailable without requesting when no company is selected", () => {
    const { result } = renderList(null);

    expect(result.current.status).toBe("unavailable");
    expect(fetchJournals).not.toHaveBeenCalled();
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
      first.resolve([journalRow(99, "Company A only")]);
    });

    expect(result.current.status).toBe("loading");

    await act(async () => {
      second.resolve([journalRow(1, "Company B only")]);
    });

    await waitFor(() => expect(result.current.status).toBe("ready"));
    expect(result.current.items.map((item) => item.description)).toEqual(["Company B only"]);
  });

  it("maps a 403 to an unavailable state", async () => {
    const { ApiError } = await import("@/lib/api/errors");
    fetchJournals.mockRejectedValue(new ApiError({ status: 403, message: "Forbidden" }));

    const { result } = renderList(1);

    await waitFor(() => expect(result.current.status).toBe("unavailable"));
  });

  it("surfaces a server error with its message", async () => {
    const { ApiError } = await import("@/lib/api/errors");
    fetchJournals.mockRejectedValue(new ApiError({ status: 500, message: "Server exploded." }));

    const { result } = renderList(1);

    await waitFor(() => expect(result.current.status).toBe("error"));
    if (result.current.status === "error") {
      expect(result.current.message).toBe("Server exploded.");
    }
  });
});
