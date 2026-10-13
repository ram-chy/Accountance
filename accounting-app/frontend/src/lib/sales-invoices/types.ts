/**
 * Sales invoice types.
 *
 * Mirrors `App\Http\Resources\SalesInvoiceResource` / `SalesInvoiceLineResource`.
 * The settlement fields (`paid_total`, `balance_due`, `is_overdue`,
 * `days_overdue`) are attached for index/show/update/post but not for the store
 * response, so they are optional here. All money is a decimal string.
 */

export const SALES_INVOICE_STATUS_VALUES = [
  "DRAFT",
  "POSTED",
  "PARTIALLY_PAID",
  "PAID",
] as const;

export type SalesInvoiceStatus = (typeof SALES_INVOICE_STATUS_VALUES)[number];

export type SalesInvoiceCustomerRef = {
  id: number;
  customer_code: string;
  name: string;
};

export type SalesInvoiceLine = {
  id: number;
  line_number: number;
  description: string | null;
  quantity: string;
  unit_price: string;
  discount: string;
  tax_rate: string;
  line_total: string;
  tax_amount: string;
  revenue_account_id: number;
};

export type SalesInvoice = {
  id: number;
  invoice_number: string;
  customer_id: number;
  invoice_date: string;
  due_date: string;
  status: SalesInvoiceStatus;
  subtotal: string;
  discount_total: string;
  tax_total: string;
  grand_total: string;
  paid_total?: string | null;
  balance_due?: string | null;
  is_overdue?: boolean | null;
  days_overdue?: number | null;
  tax_account_id: number | null;
  journal_id: number | null;
  posted_at: string | null;
  notes: string | null;
  lines: SalesInvoiceLine[];
  customer?: SalesInvoiceCustomerRef | null;
  created_at: string | null;
  updated_at: string | null;
};

export type SalesInvoiceLinePayload = {
  description: string | null;
  quantity: string;
  unit_price: string;
  discount: string;
  tax_rate: string;
  revenue_account_id: number | null;
};

export type SalesInvoiceWritePayload = {
  customer_id: number | null;
  invoice_date: string;
  due_date: string;
  notes: string | null;
  tax_account_id: number | null;
  lines: SalesInvoiceLinePayload[];
};

export type SalesInvoiceListQuery = {
  search?: string;
  status?: SalesInvoiceStatus;
  customer_id?: number;
  from?: string;
  to?: string;
  outstanding?: boolean;
  page?: number;
  per_page?: number;
};

export type SalesInvoiceListState =
  | { status: "loading"; items: []; perPage: number; hasMore: boolean }
  | { status: "ready"; items: SalesInvoice[]; perPage: number; hasMore: boolean }
  | { status: "error"; items: []; perPage: number; hasMore: boolean; message: string; requestId?: string }
  | { status: "unavailable"; items: []; perPage: number; hasMore: boolean; message: string };

export type SalesInvoiceDetailState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: SalesInvoice }
  | { status: "notfound"; data: null }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
