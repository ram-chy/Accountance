"use client";

import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api/errors";
import { normalizeList } from "@/lib/api/pagination";
import { fetchPurchaseBills } from "@/lib/purchase-bills/api";
import { parsePurchaseBill } from "@/lib/purchase-bills/validators";
import { fetchSalesInvoices } from "@/lib/sales-invoices/api";
import { parseSalesInvoice } from "@/lib/sales-invoices/validators";

import { isSalesNote, type NoteSourceDocument, type NoteSourceDocumentsState, type NoteType } from "./types";

/**
 * Posted source documents a note may adjust, for the form's picker.
 *
 * Only POSTED documents are eligible — a draft invoice or bill has no journal,
 * so the backend refuses it as a note source (CreditDebitNoteAdjustmentService).
 * The list walks pages with a defensive cap, mirroring the option hooks.
 */

const PAGE_SIZE = 100;
const MAX_PAGES = 20;

export function useNoteSourceDocuments(
  companyId: number | null,
  noteType: NoteType,
  enabled: boolean,
  reloadToken = 0,
): NoteSourceDocumentsState {
  const key = enabled && companyId !== null ? `${companyId}|${noteType}|${reloadToken}` : null;
  const sales = isSalesNote(noteType);
  const [stored, setStored] = useState<{ key: string; data: NoteSourceDocumentsState } | null>(null);

  useEffect(() => {
    if (key === null || companyId === null) {
      return;
    }

    const controller = new AbortController();
    const { signal } = controller;

    async function load(): Promise<NoteSourceDocument[]> {
      const collected: NoteSourceDocument[] = [];

      for (let page = 1; page <= MAX_PAGES; page += 1) {
        const raw = sales
          ? await fetchSalesInvoices({ status: "POSTED", page, per_page: PAGE_SIZE }, signal)
          : await fetchPurchaseBills({ status: "POSTED", page, per_page: PAGE_SIZE }, signal);

        if (signal.aborted) return [];

        const list = normalizeList<unknown>(raw, PAGE_SIZE);

        for (const entry of list.items) {
          if (sales) {
            const invoice = parseSalesInvoice(entry);
            if (invoice !== null) {
              collected.push({
                id: invoice.id,
                number: invoice.invoice_number,
                date: invoice.invoice_date,
                counterparty: invoice.customer?.name ?? null,
                grand_total: invoice.grand_total,
              });
            }
          } else {
            const bill = parsePurchaseBill(entry);
            if (bill !== null) {
              collected.push({
                id: bill.id,
                number: bill.bill_number,
                date: bill.bill_date,
                counterparty: bill.supplier?.name ?? null,
                grand_total: bill.grand_total,
              });
            }
          }
        }

        if (!list.hasMore) break;
      }

      return collected.sort((a, b) => b.id - a.id);
    }

    load()
      .then((items) => {
        if (signal.aborted) return;
        setStored({ key, data: { status: "ready", items } });
      })
      .catch((error: unknown) => {
        if (signal.aborted) return;
        setStored({
          key,
          data: {
            status: "error",
            items: [],
            message: error instanceof ApiError ? error.message : "Could not load the documents this note can adjust.",
          },
        });
      });

    return () => controller.abort();
  }, [key, companyId, sales]);

  if (!enabled) {
    return { status: "error", items: [], message: "You do not have access to these notes." };
  }
  if (companyId === null) {
    return { status: "error", items: [], message: "Select a company to choose a document." };
  }
  if (key !== null && stored && stored.key === key) {
    return stored.data;
  }
  return { status: "loading", items: [] };
}
