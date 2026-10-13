import { describe, expect, it } from "vitest";

import type { CreditDebitNoteLinePayload, CreditDebitNoteWritePayload } from "./types";
import {
  CreditDebitNoteDataError,
  parseAdjustableSource,
  parseCreditDebitNote,
  parseCreditDebitNoteLine,
  parseCreditDebitNoteList,
  parseCreditDebitNoteOrThrow,
  validateCreditDebitNoteWrite,
} from "./validators";

const validLine = {
  id: 1,
  line_number: 1,
  description: "Returned widget",
  quantity: "1.0000",
  unit_price: "100.0000",
  discount: "0.0000",
  tax_rate: "10.0000",
  line_total: "110.0000",
  tax_amount: "10.0000",
  tax_id: 3,
  account_id: 7,
  source_document_type: "sales_invoice",
  source_line_id: 21,
  created_at: "2026-06-30T00:00:00Z",
  updated_at: "2026-06-30T00:00:00Z",
};

const validNote = {
  id: 1,
  note_number: "CN-000001",
  note_type: "SALES_CREDIT_NOTE",
  is_credit: true,
  label: "Sales Credit Note",
  note_date: "2026-06-30",
  status: "DRAFT",
  subtotal: "100.0000",
  discount_total: "0.0000",
  tax_total: "10.0000",
  grand_total: "110.0000",
  reason: "Returned goods",
  reference: null,
  notes: null,
  source_document_type: "sales_invoice",
  source_document_id: 11,
  source_document_number: "INV-000011",
  sales_invoice_id: 11,
  purchase_bill_id: null,
  customer_id: 4,
  supplier_id: null,
  customer: { id: 4, customer_code: "C-001", name: "Acme" },
  supplier: null,
  tax_account_id: 5,
  journal_id: null,
  posted_at: null,
  lines: [
    {
      id: 1,
      line_number: 1,
      description: "Returned widget",
      quantity: "1.0000",
      unit_price: "100.0000",
      discount: "0.0000",
      tax_rate: "10.0000",
      line_total: "110.0000",
      tax_amount: "10.0000",
      tax_id: 3,
      account_id: 7,
      source_document_type: "sales_invoice",
      source_line_id: 21,
      created_at: "2026-06-30T00:00:00Z",
      updated_at: "2026-06-30T00:00:00Z",
    },
  ],
  created_by: 2,
  posted_by: null,
  created_at: "2026-06-30T00:00:00Z",
  updated_at: "2026-06-30T00:00:00Z",
};

function validWrite(): CreditDebitNoteWritePayload {
  return {
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
    ],
  };
}

describe("parseCreditDebitNoteLine", () => {
  it("narrows a complete line", () => {
    const parsed = parseCreditDebitNoteLine(validLine);

    expect(parsed).not.toBeNull();
    expect(parsed?.account_id).toBe(7);
    expect(parsed?.source_line_id).toBe(21);
  });

  it("drops a line missing the account", () => {
    expect(parseCreditDebitNoteLine({ ...validLine, account_id: undefined })).toBeNull();
  });

  it("accepts a document-level line with no source line", () => {
    const parsed = parseCreditDebitNoteLine({ ...validLine, source_line_id: null, source_document_type: null });

    expect(parsed?.source_line_id).toBeNull();
    expect(parsed?.source_document_type).toBeNull();
  });
});

describe("parseCreditDebitNote", () => {
  it("narrows a complete note including the counterparty", () => {
    const parsed = parseCreditDebitNote(validNote);

    expect(parsed).not.toBeNull();
    expect(parsed?.note_number).toBe("CN-000001");
    expect(parsed?.customer?.name).toBe("Acme");
    expect(parsed?.lines).toHaveLength(1);
  });

  it("rejects an unknown note type", () => {
    expect(parseCreditDebitNote({ ...validNote, note_type: "SALES_REFUND" })).toBeNull();
  });

  it("accepts a purchase note with no customer ref", () => {
    const parsed = parseCreditDebitNote({
      ...validNote,
      note_type: "PURCHASE_DEBIT_NOTE",
      is_credit: false,
      customer_id: null,
      customer: null,
      supplier_id: 3,
      supplier: { id: 3, supplier_code: "S-001", name: "Parts Ltd" },
    });

    expect(parsed?.customer).toBeNull();
    expect(parsed?.supplier?.name).toBe("Parts Ltd");
  });

  it("rejects a note missing the source document id", () => {
    expect(parseCreditDebitNote({ ...validNote, source_document_id: undefined })).toBeNull();
  });
});

describe("parseCreditDebitNoteList", () => {
  it("narrows a list and reports readiness", () => {
    const parsed = parseCreditDebitNoteList([validNote], 25);

    expect(parsed.status).toBe("ready");
    expect(parsed.items).toHaveLength(1);
  });

  it("errors when every row is invalid", () => {
    const parsed = parseCreditDebitNoteList([{ nope: true }], 25);

    expect(parsed.status).toBe("error");
  });
});

describe("parseCreditDebitNoteOrThrow", () => {
  it("throws the module error on an invalid note", () => {
    expect(() => parseCreditDebitNoteOrThrow(null)).toThrow(CreditDebitNoteDataError);
  });
});

describe("parseAdjustableSource", () => {
  const invoiceSource = {
    sales_invoice_id: 11,
    invoice_number: "INV-000011",
    grand_total: "1000.0000",
    net_adjustment: "200.0000",
    remaining_adjustable_amount: "800.0000",
    lines: [
      {
        sales_invoice_line_id: 21,
        line_number: 1,
        description: "Widget",
        quantity: "10.0000",
        unit_price: "100.0000",
        tax_rate: "0.0000",
        tax_id: null,
        line_total: "1000.0000",
        adjusted_quantity: "2.0000",
        remaining_quantity: "8.0000",
        is_adjustable: true,
      },
    ],
  };

  it("normalises the invoice side including its price field", () => {
    const parsed = parseAdjustableSource(invoiceSource);

    expect(parsed).not.toBeNull();
    expect(parsed?.source_document_type).toBe("sales_invoice");
    expect(parsed?.document_number).toBe("INV-000011");
    expect(parsed?.lines[0].source_line_id).toBe(21);
    expect(parsed?.lines[0].unit_price).toBe("100.0000");
    expect(parsed?.remaining_adjustable_amount).toBe("800.0000");
  });

  it("maps a bill's unit_cost onto unit_price", () => {
    const parsed = parseAdjustableSource({
      purchase_bill_id: 31,
      bill_number: "BILL-000031",
      grand_total: "500.0000",
      net_adjustment: "0.0000",
      remaining_adjustable_amount: "500.0000",
      lines: [
        {
          purchase_bill_line_id: 41,
          line_number: 1,
          description: "Parts",
          quantity: "5.0000",
          unit_cost: "100.0000",
          tax_rate: "0.0000",
          tax_id: null,
          line_total: "500.0000",
          adjusted_quantity: "0.0000",
          remaining_quantity: "5.0000",
          is_adjustable: true,
        },
      ],
    });

    expect(parsed?.source_document_type).toBe("purchase_bill");
    expect(parsed?.document_number).toBe("BILL-000031");
    expect(parsed?.lines[0].source_line_id).toBe(41);
    expect(parsed?.lines[0].unit_price).toBe("100.0000");
  });

  it("rejects a response with both source ids", () => {
    expect(parseAdjustableSource({ ...invoiceSource, purchase_bill_id: 31 })).toBeNull();
  });
});

describe("validateCreditDebitNoteWrite", () => {
  it("accepts a valid payload", () => {
    expect(validateCreditDebitNoteWrite(validWrite())).toEqual({});
  });

  it("requires a source document", () => {
    expect(validateCreditDebitNoteWrite({ ...validWrite(), source_id: null }).source_id).toBeDefined();
  });

  it("requires a well-formed date", () => {
    expect(validateCreditDebitNoteWrite({ ...validWrite(), note_date: "30-06-2026" }).note_date).toBeDefined();
  });

  it("requires a reason", () => {
    expect(validateCreditDebitNoteWrite({ ...validWrite(), reason: "   " }).reason).toBeDefined();
  });

  it("requires at least one line", () => {
    expect(validateCreditDebitNoteWrite({ ...validWrite(), lines: [] }).lines).toBeDefined();
  });

  it("rejects a zero quantity and a missing account", () => {
    const line: CreditDebitNoteLinePayload = {
      description: "",
      quantity: "0",
      unit_price: "100",
      discount: "0",
      tax_rate: "0",
      account_id: null,
      source_line_id: null,
    };

    const errors = validateCreditDebitNoteWrite({ ...validWrite(), lines: [line] });
    expect(errors["lines.0.quantity"]).toBeDefined();
    expect(errors["lines.0.account_id"]).toBeDefined();
  });

  it("rejects a discount larger than the line gross", () => {
    const line: CreditDebitNoteLinePayload = {
      description: "",
      quantity: "1",
      unit_price: "100",
      discount: "150",
      tax_rate: "0",
      account_id: 7,
      source_line_id: null,
    };

    const errors = validateCreditDebitNoteWrite({ ...validWrite(), lines: [line] });
    expect(errors["lines.0.discount"]).toBeDefined();
  });

  it("rejects more than 500 lines", () => {
    const line: CreditDebitNoteLinePayload = {
      description: "",
      quantity: "1",
      unit_price: "100",
      discount: "0",
      tax_rate: "0",
      account_id: 7,
      source_line_id: null,
    };

    const errors = validateCreditDebitNoteWrite({
      ...validWrite(),
      lines: Array.from({ length: 501 }, () => ({ ...line })),
    });
    expect(errors.lines).toBeDefined();
  });
});
