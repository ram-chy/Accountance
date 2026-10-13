/**
 * Supplier types.
 *
 * Mirrors `App\Http\Resources\SupplierResource` and `Store/UpdateSupplierRequest`.
 * `payable_account` is only present when the backend eager-loads the relation; it
 * is optional here so a store/show payload both parse. The counterpart of
 * `lib/customers/types.ts` with the receivable account swapped for the payable
 * account.
 */

export type SupplierAccountRef = {
  id: number;
  code: string;
  name: string;
};

export type Supplier = {
  id: number;
  supplier_code: string;
  name: string;
  email: string | null;
  phone: string | null;
  address: string | null;
  city: string | null;
  state: string | null;
  country_code: string | null;
  tax_identifier: string | null;
  payable_account_id: number;
  is_active: boolean;
  payable_account?: SupplierAccountRef | null;
  created_at: string | null;
  updated_at: string | null;
};

export type SupplierWritePayload = {
  supplier_code: string;
  name: string;
  email: string | null;
  phone: string | null;
  address: string | null;
  city: string | null;
  state: string | null;
  country_code: string | null;
  tax_identifier: string | null;
  payable_account_id: number | null;
};

export type SupplierListQuery = {
  search?: string;
  is_active?: boolean;
  page?: number;
  per_page?: number;
};

export type SupplierListState =
  | { status: "loading"; items: []; perPage: number; hasMore: boolean }
  | { status: "ready"; items: Supplier[]; perPage: number; hasMore: boolean }
  | { status: "error"; items: []; perPage: number; hasMore: boolean; message: string; requestId?: string }
  | { status: "unavailable"; items: []; perPage: number; hasMore: boolean; message: string };

export type SupplierDetailState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: Supplier }
  | { status: "notfound"; data: null }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
