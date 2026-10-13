/**
 * Supplier payment types.
 *
 * Mirrors `App\Http\Resources\SupplierPaymentResource` and
 * `SupplierPaymentAllocationResource`. Payments have only two states — DRAFT and
 * POSTED; "partially paid" is a property of the settled bills, not the payment.
 * `amount` and allocation amounts are decimal strings.
 */

export const SUPPLIER_PAYMENT_STATUS_VALUES = ["DRAFT", "POSTED"] as const;

export type SupplierPaymentStatus = (typeof SUPPLIER_PAYMENT_STATUS_VALUES)[number];

export type PaymentBillRef = {
  id: number;
  bill_number: string;
  status?: string;
  grand_total?: string;
  balance_due?: string | null;
};

export type SupplierPaymentAllocation = {
  id: number;
  supplier_payment_id: number;
  purchase_bill_id: number;
  amount: string;
  purchase_bill?: PaymentBillRef | null;
};

export type SupplierPaymentSupplierRef = {
  id: number;
  supplier_code: string;
  name: string;
};

export type SupplierPayment = {
  id: number;
  payment_number: string;
  supplier_id: number;
  payment_date: string;
  amount: string;
  status: SupplierPaymentStatus;
  payment_account_id: number;
  reference: string | null;
  notes: string | null;
  allocations: SupplierPaymentAllocation[];
  supplier?: SupplierPaymentSupplierRef | null;
  journal_id: number | null;
  posted_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type SupplierBillAllocationPayload = {
  purchase_bill_id: number;
  amount: string;
};

export type SupplierPaymentWritePayload = {
  supplier_id: number | null;
  payment_date: string;
  amount: string;
  payment_account_id: number | null;
  reference: string | null;
  notes: string | null;
  allocations: SupplierBillAllocationPayload[];
};

export type SupplierPaymentListQuery = {
  status?: SupplierPaymentStatus;
  supplier_id?: number;
  from?: string;
  to?: string;
  reference?: string;
  page?: number;
  per_page?: number;
};

export type SupplierPaymentListState =
  | { status: "loading"; items: []; perPage: number; hasMore: boolean }
  | { status: "ready"; items: SupplierPayment[]; perPage: number; hasMore: boolean }
  | { status: "error"; items: []; perPage: number; hasMore: boolean; message: string; requestId?: string }
  | { status: "unavailable"; items: []; perPage: number; hasMore: boolean; message: string };

export type SupplierPaymentDetailState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: SupplierPayment }
  | { status: "notfound"; data: null }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
