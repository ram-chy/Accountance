"use client";

import { backendDelete, backendGet, backendPost, backendPut } from "@/lib/api/backend";
import type { QueryParams } from "@/lib/api/types";

import type { JournalStatusValue, JournalWritePayload } from "./types";

/**
 * Journal backend calls.
 *
 * Every request goes through the same-origin proxy (`/api/backend/*`), which
 * attaches the server-held bearer token and the active `X-Company-Id`. The
 * browser never calls Laravel directly and never sends `company_id` — the scope
 * comes from the server-side company context.
 *
 * List responses are returned as `unknown` and narrowed by `validators.ts`;
 * write responses are narrowed by `parseJournalDetail` in the mutation layer.
 */

export type JournalListQuery = {
  search?: string;
  status?: JournalStatusValue;
  /** Inclusive lower bound (`Y-m-d`). */
  from?: string;
  /** Inclusive upper bound (`Y-m-d`). */
  to?: string;
  page?: number;
  per_page?: number;
};

export function fetchJournals(
  query: JournalListQuery,
  signal?: AbortSignal,
): Promise<unknown> {
  const params: QueryParams = {
    search: query.search || undefined,
    status: query.status || undefined,
    from: query.from || undefined,
    to: query.to || undefined,
    page: query.page,
    per_page: query.per_page,
  };

  return backendGet("journals", { query: params, signal });
}

export function fetchJournal(journalId: number, signal?: AbortSignal): Promise<unknown> {
  return backendGet(`journals/${journalId}`, { signal });
}

export function createJournal(payload: JournalWritePayload): Promise<unknown> {
  return backendPost("journals", payload);
}

export function updateJournal(
  journalId: number,
  payload: JournalWritePayload,
): Promise<unknown> {
  return backendPut(`journals/${journalId}`, payload);
}

export function deleteJournal(journalId: number): Promise<unknown> {
  return backendDelete(`journals/${journalId}`);
}

/** Post a draft into the accounting record. No body is accepted. */
export function postJournal(journalId: number): Promise<unknown> {
  return backendPost(`journals/${journalId}/post`);
}
