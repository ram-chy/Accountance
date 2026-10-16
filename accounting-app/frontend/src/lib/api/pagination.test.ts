import { describe, expect, it } from "vitest";

import { normalizeList } from "./pagination";

/**
 * Phase 21 regression for the three list shapes the backend actually emits:
 *
 * 1. The normal envelope — `{ success, message, data: [...] }` — already
 *    unwrapped to `data` by `apiFetch`, so consumers see a flat array.
 * 2. The envelope exceptions: `/accounting/currencies*` and
 *    `/accounting/fx-settings` return a bare `{ data: [...] }` with no
 *    `success`/`message`, which `apiFetch` also unwraps to the array.
 * 3. A future Laravel paginator (`{ data, current_page, last_page, total }`),
 *    which `apiFetch` unwraps to the paginator object itself.
 *
 * Fabricating `meta`/`hasMore` for shape 1 and 2 would invent pagination the
 * contract does not provide, so `meta` must stay undefined there.
 */
describe("normalizeList", () => {
  it("accepts the current flat data array without inventing metadata", () => {
    const result = normalizeList([{ id: 1 }, { id: 2 }], 25);

    expect(result.items).toHaveLength(2);
    expect(result.perPage).toBe(25);
    expect(result.meta).toBeUndefined();
    // Fewer items than perPage: no further page signalled.
    expect(result.hasMore).toBe(false);
  });

  it("infers a possible next page only from a full page", () => {
    const full = Array.from({ length: 25 }, (_, i) => ({ id: i }));

    expect(normalizeList(full, 25).hasMore).toBe(true);
    expect(normalizeList(full.slice(0, 24), 25).hasMore).toBe(false);
  });

  it("accepts a bare { data: [...] } body (currencies/fx-settings exception)", () => {
    const result = normalizeList({ data: [{ id: 7 }] }, 25);

    expect(result.items).toEqual([{ id: 7 }]);
    expect(result.meta).toBeUndefined();
  });

  it("accepts a Laravel paginator and uses its metadata for hasMore", () => {
    const result = normalizeList(
      {
        data: [{ id: 1 }],
        current_page: 2,
        last_page: 3,
        total: 60,
      },
      25,
    );

    expect(result.items).toEqual([{ id: 1 }]);
    expect(result.meta).toEqual({ currentPage: 2, lastPage: 3, total: 60 });
    expect(result.hasMore).toBe(true);
  });

  it("reports the last page exactly when metadata says so", () => {
    const result = normalizeList(
      { data: [{ id: 1 }], current_page: 3, last_page: 3 },
      25,
    );

    expect(result.hasMore).toBe(false);
  });

  it("unwraps a double-nested data envelope", () => {
    const result = normalizeList({ data: { data: [{ id: 3 }] } }, 25);

    expect(result.items).toEqual([{ id: 3 }]);
  });

  it("returns an empty list for unexpected shapes instead of throwing", () => {
    expect(normalizeList(null).items).toEqual([]);
    expect(normalizeList("nope").items).toEqual([]);
    expect(normalizeList({ unexpected: true }).items).toEqual([]);
  });
});
