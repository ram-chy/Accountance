import { afterEach, describe, expect, it, vi } from "vitest";

import {
  createJournal,
  deleteJournal,
  fetchJournal,
  fetchJournals,
  postJournal,
  updateJournal,
} from "./api";
import type { JournalWritePayload } from "./types";

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

function firstRequest(fetchMock: ReturnType<typeof vi.fn>): {
  url: string;
  options: RequestInit;
} {
  const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit];
  return { url, options };
}

afterEach(() => {
  vi.unstubAllGlobals();
});

const payload: JournalWritePayload = {
  journal_date: "2027-01-15",
  description: "Opening entry",
  reference: "INV-1",
  lines: [
    { account_id: 10, description: null, debit: "100", credit: "0" },
    { account_id: 20, description: null, debit: "0", credit: "100" },
  ],
};

describe("fetchJournals", () => {
  it("routes through the proxy and omits empty filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchJournals({});

    expect(firstRequest(fetchMock).url).toBe("/api/backend/journals");
  });

  it("maps filters to the verified query string", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchJournals({
      search: "cash",
      status: "POSTED",
      from: "2027-01-01",
      to: "2027-01-31",
      page: 2,
      per_page: 25,
    });

    const { url } = firstRequest(fetchMock);
    expect(url).toContain("/api/backend/journals?");
    expect(url).toContain("search=cash");
    expect(url).toContain("status=POSTED");
    expect(url).toContain("from=2027-01-01");
    expect(url).toContain("to=2027-01-31");
    expect(url).toContain("page=2");
    expect(url).toContain("per_page=25");
  });

  it("passes an abort signal to fetch", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });
    const controller = new AbortController();

    await fetchJournals({}, controller.signal);

    expect(firstRequest(fetchMock).options.signal).toBe(controller.signal);
  });
});

describe("journal writes", () => {
  it("reads one journal", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 3 } });

    await fetchJournal(3);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/journals/3");
    expect(options.method).toBe("GET");
  });

  it("creates a journal with a POST body", async () => {
    const fetchMock = mockJson(201, { success: true, data: { id: 1 } });

    await createJournal(payload);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/journals");
    expect(options.method).toBe("POST");
    expect(JSON.parse(String(options.body))).toEqual(payload);
  });

  it("updates a journal with a PUT body", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 1 } });

    await updateJournal(1, payload);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/journals/1");
    expect(options.method).toBe("PUT");
  });

  it("deletes a journal with DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteJournal(9);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/journals/9");
    expect(options.method).toBe("DELETE");
  });

  it("posts a journal via the post sub-resource", async () => {
    const fetchMock = mockJson(200, { success: true, data: { id: 7 } });

    await postJournal(7);

    const { url, options } = firstRequest(fetchMock);
    expect(url).toBe("/api/backend/journals/7/post");
    expect(options.method).toBe("POST");
  });
});
