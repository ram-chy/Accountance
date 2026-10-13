/**
 * Purchase bill types.
 *
 * Mirrors `App\Http\Resources\PurchaseBillResource` / `PurchaseBillLineResource`.
 * A bill reuses the sales-invoice status vocabulary (`DRAFT`, `POSTED`,
 * `PARTIALLY_PAID`, `PAID`) but its lines price goods at `unit_cost` and post to
 * an `expense_account_id`. The settlement fields are attached for
 * index/show/update/post but not for the store response, so they are optional.
 * All money is a decimal string.
 */

export const PURCHASE_BILL_STATUS_VALUES = [
  "DRAFT",
  "POSTED",
  "PARTIALLY_PAID",
  "PAID",
] as const;

export type PurchaseBillStatus = (typeof PURCHASE_BILL_STATUS_VALUES)[number];

export type PurchaseBillSupplierRef = {
  id: number;
  supplier_code: string;
  name: string;
};

export type PurchaseBillLine = {
  id: number;
  line_number: number;
  description: string | null;
  quantity: string;
  unit_cost: string;
  discount: string;
  tax_rate: string;
  line_total: string;
  tax_amount: string;
  expense_account_id: number;
};

export type PurchaseBill = {
  id: number;
  bill_number: string;
  supplier_id: number;
  bill_date: string;
  due_date: string;
  status: PurchaseBillStatus;
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
  lines: PurchaseBillLine[];
  supplier?: PurchaseBillSupplierRef | null;
  created_at: string | null;
  updated_at: string | null;
};

export type PurchaseBillLinePayload = {
  description: string | null;
  quantity: string;
  unit_cost: string;
  discount: string;
  tax_rate: string;
  expense_account_id: number | null;
};

export type PurchaseBillWritePayload = {
  supplier_id: number | null;
  bill_date: string;
  due_date: string;
  notes: string | null;
  tax_account_id: number | null;
  lines: PurchaseBillLinePayload[];
};

export type PurchaseBillListQuery = {
  search?: string;
  status?: PurchaseBillStatus;
  supplier_id?: number;
  from?: string;
  to?: string;
  outstanding?: boolean;
  page?: number;
  per_page?: number;
};

export type PurchaseBillListState =
  | { status: "loading"; items: []; perPage: number; hasMore: boolean }
  | { status: "ready"; items: PurchaseBill[]; perPage: number; hasMore: boolean }
  | { status: "error"; items: []; perPage: number; hasMore: boolean; message: string; requestId?: string }
  | { status: "unavailable"; items: []; perPage: number; hasMore: boolean; message: string };

export type PurchaseBillDetailState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: PurchaseBill }
  | { status: "notfound"; data: null }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
