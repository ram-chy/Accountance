import { afterEach, describe, expect, it, vi } from "vitest";

import {
  createCreditDebitNote,
  deleteCreditDebitNote,
  fetchAdjustableLines,
  fetchCreditDebitNote,
  fetchCreditDebitNotes,
  postCreditDebitNote,
  updateCreditDebitNote,
} from "./api";
import type { CreditDebitNoteWritePayload } from "./types";

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

function firstRequest(fetchMock: ReturnType<typeof vi.fn>): { url: string; options: RequestInit } {
  const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit];
  return { url, options };
}

function bodyOf(options: RequestInit): Record<string, unknown> {
  return JSON.parse(options.body as string) as Record<string, unknown>;
}

const salesPayload: CreditDebitNoteWritePayload = {
  note_type: "SALES_CREDIT_NOTE",
  source_id: 11,
  note_date: "2026-06-30",
  reason: "Returned goods",
  reference: null,
  notes: null,
  tax_account_id: 5,
  lines: [
    {
      description: "Widget",
      quantity: "1",
      unit_price: "100",
      discount: "0",
      tax_rate: "10",
      account_id: 7,
      source_line_id: 21,
    },
    {
      description: "Freight",
      quantity: "1",
      unit_price: "10",
      discount: "0",
      tax_rate: "0",
      account_id: 7,
      source_line_id: null,
    },
  ],
};

const purchasePayload: CreditDebitNoteWritePayload = {
  ...salesPayload,
  note_type: "PURCHASE_DEBIT_NOTE",
  source_id: 31,
};

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("fetchCreditDebitNotes", () => {
  it("routes the list through the proxy with the note type and filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchCreditDebitNotes({ note_type: "SALES_CREDIT_NOTE", status: "DRAFT", customer_id: 4, page: 2, per_page: 25 });

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("GET");
    expect(url).toContain("/api/backend/credit-debit-notes?");
    expect(url).toContain("note_type=SALES_CREDIT_NOTE");
    expect(url).toContain("status=DRAFT");
    expect(url).toContain("customer_id=4");
    expect(url).toContain("page=2");
  });

  it("omits empty filters", async () => {
    const fetchMock = mockJson(200, { success: true, data: [] });

    await fetchCreditDebitNotes({ note_type: "PURCHASE_DEBIT_NOTE" });

    expect(firstRequest(fetchMock).url).toBe("/api/backend/credit-debit-notes?note_type=PURCHASE_DEBIT_NOTE");
  });
});

describe("fetchCreditDebitNote", () => {
  it("requests one note by id", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchCreditDebitNote(5);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/credit-debit-notes/5");
  });
});

describe("write operations", () => {
  it("creates a sales credit note with the sales_invoice_id field", async () => {
    const fetchMock = mockJson(201, { success: true, data: {} });

    await createCreditDebitNote(salesPayload);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("POST");
    expect(url).toBe("/api/backend/credit-debit-notes");

    const body = bodyOf(options);
    expect(body.note_type).toBe("SALES_CREDIT_NOTE");
    expect(body.sales_invoice_id).toBe(11);
    expect(body.purchase_bill_id).toBeUndefined();

    const lines = body.lines as Record<string, unknown>[];
    expect(lines[0].sales_invoice_line_id).toBe(21);
    expect(lines[1].sales_invoice_line_id).toBeUndefined();
  });

  it("creates a purchase debit note with the purchase_bill_id field", async () => {
    const fetchMock = mockJson(201, { success: true, data: {} });

    await createCreditDebitNote({
      ...purchasePayload,
      lines: [{ ...purchasePayload.lines[0], source_line_id: 41 }],
    });

    const body = bodyOf(firstRequest(fetchMock).options);
    expect(body.note_type).toBe("PURCHASE_DEBIT_NOTE");
    expect(body.purchase_bill_id).toBe(31);
    expect(body.sales_invoice_id).toBeUndefined();
    const lines = body.lines as Record<string, unknown>[];
    expect(lines[0].purchase_bill_line_id).toBe(41);
  });

  it("updates through PUT without the type or source", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await updateCreditDebitNote(5, salesPayload);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("PUT");
    expect(url).toBe("/api/backend/credit-debit-notes/5");

    const body = bodyOf(options);
    expect(body.note_type).toBeUndefined();
    expect(body.sales_invoice_id).toBeUndefined();
    expect(body.purchase_bill_id).toBeUndefined();
    expect(body.note_date).toBe("2026-06-30");
  });

  it("posts a draft without a body", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await postCreditDebitNote(5);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("POST");
    expect(url).toBe("/api/backend/credit-debit-notes/5/post");
    expect(options.body).toBeUndefined();
  });

  it("deletes through DELETE", async () => {
    const fetchMock = mockJson(200, { success: true });

    await deleteCreditDebitNote(5);

    const { url, options } = firstRequest(fetchMock);
    expect(options.method).toBe("DELETE");
    expect(url).toBe("/api/backend/credit-debit-notes/5");
  });
});

describe("fetchAdjustableLines", () => {
  it("asks the sales invoice for its adjustable lines on a sales note", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchAdjustableLines("SALES_CREDIT_NOTE", 11);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/sales-invoices/11/adjustable-lines");
  });

  it("asks the purchase bill for its adjustable lines on a purchase note", async () => {
    const fetchMock = mockJson(200, { success: true, data: {} });

    await fetchAdjustableLines("PURCHASE_DEBIT_NOTE", 31);

    expect(firstRequest(fetchMock).url).toBe("/api/backend/purchase-bills/31/adjustable-lines");
  });
});
