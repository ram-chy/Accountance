import { DEFAULT_PER_PAGE } from "./config";
import type { PaginatorMeta } from "./types";

export type ListMeta = {
  currentPage?: number;
  lastPage?: number;
  total?: number;
};

export type ListResult<T> = {
  items: T[];
  /** Page size used to interpret this payload. */
  perPage: number;
  /**
   * True when another page may exist.
   *
   * The backend currently returns a flat `data` array with no `total` or
   * `last_page` (Phase 0 audit finding P2-1), so a full page is the only
   * available signal that more rows might follow. If the backend later adds a
   * Laravel paginator, `meta` is populated and `hasMore` becomes exact.
   */
  hasMore: boolean;
  meta?: ListMeta;
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function readMeta(value: unknown): ListMeta | undefined {
  if (!isRecord(value)) {
    return undefined;
  }

  const source = value as PaginatorMeta;
  const meta: ListMeta = {};

  if (typeof source.current_page === "number") {
    meta.currentPage = source.current_page;
  }
  if (typeof source.last_page === "number") {
    meta.lastPage = source.last_page;
  }
  if (typeof source.total === "number") {
    meta.total = source.total;
  }

  return Object.keys(meta).length > 0 ? meta : undefined;
}

function mergeMeta(
  base: ListMeta | undefined,
  override: ListMeta | undefined,
): ListMeta | undefined {
  const merged = { ...base, ...override };
  return Object.keys(merged).length > 0 ? merged : undefined;
}

function computeHasMore(
  itemCount: number,
  perPage: number,
  meta: ListMeta | undefined,
): boolean {
  if (
    meta?.currentPage !== undefined &&
    meta?.lastPage !== undefined &&
    perPage > 0
  ) {
    return meta.currentPage < meta.lastPage;
  }

  return perPage > 0 && itemCount >= perPage;
}

function result<T>(
  items: T[],
  perPage: number,
  meta: ListMeta | undefined,
): ListResult<T> {
  return { items, perPage, hasMore: computeHasMore(items.length, perPage, meta), meta };
}

/**
 * Normalise a list response into a stable shape.
 *
 * Accepts either the backend's current flat array, a Laravel paginator
 * (`{ data: [...], current_page, last_page, ... }`), or a nested envelope, so
 * consumer code does not need to know which shape it is talking to.
 */
export function normalizeList<T>(
  payload: unknown,
  perPage: number = DEFAULT_PER_PAGE,
): ListResult<T> {
  if (Array.isArray(payload)) {
    return result(payload as T[], perPage, undefined);
  }

  if (!isRecord(payload)) {
    return result<T>([], perPage, undefined);
  }

  const outerMeta = readMeta(payload.meta) ?? readMeta(payload);

  if (Array.isArray(payload.data)) {
    return result(payload.data as T[], perPage, outerMeta);
  }

  if (isRecord(payload.data)) {
    const inner = payload.data;

    if (Array.isArray(inner.data)) {
      const meta = mergeMeta(readMeta(inner), outerMeta);
      return result(inner.data as T[], perPage, meta);
    }
  }

  return result<T>([], perPage, outerMeta);
}
