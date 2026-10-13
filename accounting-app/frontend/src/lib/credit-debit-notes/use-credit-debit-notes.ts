"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";

import { fetchCreditDebitNotes } from "./api";
import type { CreditDebitNoteListQuery, CreditDebitNoteListState } from "./types";
import { parseCreditDebitNoteList } from "./validators";

const NO_COMPANY_REASON = "Select a company to see its credit and debit notes.";
const PERMISSION_REASON = "Your role does not include access to credit and debit notes.";

export const CREDIT_DEBIT_NOTE_LIST_PER_PAGE = 25;

export type UseCreditDebitNotesOptions = {
  companyId: number | null;
  enabled: boolean;
  query: CreditDebitNoteListQuery;
  reloadToken: number;
};

function runKey(
  companyId: number | null,
  query: CreditDebitNoteListQuery,
  reloadToken: number,
): string {
  return [
    companyId ?? "",
    query.note_type ?? "",
    query.status ?? "",
    query.customer_id ?? "",
    query.supplier_id ?? "",
    query.sales_invoice_id ?? "",
    query.purchase_bill_id ?? "",
    query.search ?? "",
    query.from ?? "",
    query.to ?? "",
    query.page ?? 1,
    query.per_page ?? CREDIT_DEBIT_NOTE_LIST_PER_PAGE,
    reloadToken,
  ].join("|");
}

export function useCreditDebitNotes(
  options: UseCreditDebitNotesOptions,
): CreditDebitNoteListState {
  const { companyId, enabled, query, reloadToken } = options;
  const key = enabled && companyId !== null ? runKey(companyId, query, reloadToken) : null;
  const [stored, setStored] = useState<{ key: string; data: CreditDebitNoteListState } | null>(null);

  const { note_type, status, customer_id, supplier_id, sales_invoice_id, purchase_bill_id, search, from, to, page, per_page } = query;

  useEffect(() => {
    if (companyId === null || key === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    fetchCreditDebitNotes(
      { note_type, status, customer_id, supplier_id, sales_invoice_id, purchase_bill_id, search, from, to, page, per_page },
      signal,
    )
      .then((raw) => {
        if (signal.aborted) return;
        setStored({ key, data: parseCreditDebitNoteList(raw, per_page ?? CREDIT_DEBIT_NOTE_LIST_PER_PAGE) });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;

        if (error instanceof ApiError && error.status === 403) {
          setStored({
            key,
            data: { status: "unavailable", items: [], perPage: 0, hasMore: false, message: PERMISSION_REASON },
          });
          return;
        }

        setStored({
          key,
          data: {
            status: "error",
            items: [],
            perPage: 0,
            hasMore: false,
            message: error instanceof ApiError ? error.message : "Could not load credit and debit notes. Please try again.",
            requestId: error instanceof ApiError ? error.requestId : undefined,
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, note_type, status, customer_id, supplier_id, sales_invoice_id, purchase_bill_id, search, from, to, page, per_page]);

  if (!enabled) {
    return { status: "unavailable", items: [], perPage: 0, hasMore: false, message: PERMISSION_REASON };
  }
  if (companyId === null) {
    return { status: "unavailable", items: [], perPage: 0, hasMore: false, message: NO_COMPANY_REASON };
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", items: [], perPage: 0, hasMore: false };
}
