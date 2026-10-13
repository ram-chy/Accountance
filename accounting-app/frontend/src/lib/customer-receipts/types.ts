/**
 * Customer receipt types.
 *
 * Mirrors `App\Http\Resources\CustomerReceiptResource` and
 * `CustomerReceiptAllocationResource`. Receipts have only two states — DRAFT and
 * POSTED; "partially paid" is a property of the settled invoices, not the
 * receipt. `amount` and allocation amounts are decimal strings.
 */

export const CUSTOMER_RECEIPT_STATUS_VALUES = ["DRAFT", "POSTED"] as const;

export type CustomerReceiptStatus = (typeof CUSTOMER_RECEIPT_STATUS_VALUES)[number];

export type ReceiptInvoiceRef = {
  id: number;
  invoice_number: string;
  status?: string;
  grand_total?: string;
  balance_due?: string | null;
};

export type CustomerReceiptAllocation = {
  id: number;
  customer_receipt_id: number;
  sales_invoice_id: number;
  amount: string;
  sales_invoice?: ReceiptInvoiceRef | null;
};

export type CustomerReceiptCustomerRef = {
  id: number;
  customer_code: string;
  name: string;
};

export type CustomerReceipt = {
  id: number;
  receipt_number: string;
  customer_id: number;
  receipt_date: string;
  amount: string;
  status: CustomerReceiptStatus;
  payment_account_id: number;
  reference: string | null;
  notes: string | null;
  allocations: CustomerReceiptAllocation[];
  customer?: CustomerReceiptCustomerRef | null;
  journal_id: number | null;
  posted_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type ReceiptAllocationPayload = {
  sales_invoice_id: number;
  amount: string;
};

export type CustomerReceiptWritePayload = {
  customer_id: number | null;
  receipt_date: string;
  amount: string;
  payment_account_id: number | null;
  reference: string | null;
  notes: string | null;
  allocations: ReceiptAllocationPayload[];
};

export type CustomerReceiptListQuery = {
  status?: CustomerReceiptStatus;
  customer_id?: number;
  from?: string;
  to?: string;
  reference?: string;
  page?: number;
  per_page?: number;
};

export type CustomerReceiptListState =
  | { status: "loading"; items: []; perPage: number; hasMore: boolean }
  | { status: "ready"; items: CustomerReceipt[]; perPage: number; hasMore: boolean }
  | { status: "error"; items: []; perPage: number; hasMore: boolean; message: string; requestId?: string }
  | { status: "unavailable"; items: []; perPage: number; hasMore: boolean; message: string };

export type CustomerReceiptDetailState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: CustomerReceipt }
  | { status: "notfound"; data: null }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
