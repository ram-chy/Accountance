/**
 * Customer types.
 *
 * Mirrors `App\Http\Resources\CustomerResource` and `Store/UpdateCustomerRequest`.
 * `receivable_account` is only present when the backend eager-loads the
 * relation; it is optional here so a store/show payload both parse.
 */

export type CustomerAccountRef = {
  id: number;
  code: string;
  name: string;
};

export type Customer = {
  id: number;
  customer_code: string;
  name: string;
  email: string | null;
  phone: string | null;
  address: string | null;
  city: string | null;
  state: string | null;
  country_code: string | null;
  tax_identifier: string | null;
  receivable_account_id: number;
  is_active: boolean;
  receivable_account?: CustomerAccountRef | null;
  created_at: string | null;
  updated_at: string | null;
};

export type CustomerWritePayload = {
  customer_code: string;
  name: string;
  email: string | null;
  phone: string | null;
  address: string | null;
  city: string | null;
  state: string | null;
  country_code: string | null;
  tax_identifier: string | null;
  receivable_account_id: number | null;
};

export type CustomerListQuery = {
  search?: string;
  is_active?: boolean;
  page?: number;
  per_page?: number;
};

export type CustomerListState =
  | { status: "loading"; items: []; perPage: number; hasMore: boolean }
  | { status: "ready"; items: Customer[]; perPage: number; hasMore: boolean }
  | { status: "error"; items: []; perPage: number; hasMore: boolean; message: string; requestId?: string }
  | { status: "unavailable"; items: []; perPage: number; hasMore: boolean; message: string };

export type CustomerDetailState =
  | { status: "loading"; data: null }
  | { status: "ready"; data: Customer }
  | { status: "notfound"; data: null }
  | { status: "error"; data: null; message: string; requestId?: string }
  | { status: "unavailable"; data: null; message: string };
