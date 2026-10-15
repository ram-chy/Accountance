/**
 * Financial-dimension data contracts.
 *
 * These mirror the verified Laravel `FinancialDimensionResource` and
 * `FinancialDimensionValueResource` exactly (see
 * `FinancialDimensionController.php`, `FinancialDimensionResource.php`,
 * `FinancialDimensionValueResource.php` and the routes in `routes/api.php`).
 *
 * A dimension is one analytical axis the ledger may be cut along (`type`), with
 * a company-scoped `code`/`name`, and a set of `values` ("CC-001 Sales" under a
 * COST_CENTER). The type is fixed at creation and never echoed as editable; the
 * company is never echoed at all because a dimension is always read through the
 * caller's own company context.
 *
 * Payloads for `POST /accounting/dimensions` and
 * `PUT /accounting/dimensions/{dimension}` are written in the snake_case the
 * backend validates. `is_active` and `company_id` are server-owned and are never
 * sent by the browser.
 */

export type FinancialDimensionTypeValue =
  | "COST_CENTER"
  | "PROJECT"
  | "DEPARTMENT"
  | "LOCATION";

export const FINANCIAL_DIMENSION_TYPE_VALUES: readonly FinancialDimensionTypeValue[] = [
  "COST_CENTER",
  "PROJECT",
  "DEPARTMENT",
  "LOCATION",
] as const;

/**
 * Human labels for the four dimension types.
 *
 * These are kept client-side only so an unloaded select can render a friendly
 * option before the backend's own `type_label` arrives; a saved row always
 * displays the backend's `type_label` verbatim.
 */
export const FINANCIAL_DIMENSION_TYPE_LABELS: Record<FinancialDimensionTypeValue, string> = {
  COST_CENTER: "Cost Center",
  PROJECT: "Project",
  DEPARTMENT: "Department",
  LOCATION: "Location",
};

/** One row from the verified `FinancialDimensionValueResource`. */
export type FinancialDimensionValue = {
  id: number;
  financial_dimension_id: number;
  code: string;
  name: string;
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
};

/** One row from the verified `FinancialDimensionResource`. */
export type FinancialDimension = {
  id: number;
  type: FinancialDimensionTypeValue;
  type_label: string;
  code: string;
  name: string;
  is_active: boolean;
  /** Present only when the controller loaded the dimension's values. */
  values?: FinancialDimensionValue[];
  /** Present only when the controller counted them (`withCount('values')`). */
  values_count?: number | null;
  created_at: string | null;
  updated_at: string | null;
};

/** Client-side list filters; empty means "all". */
export type FinancialDimensionListFilters = {
  type?: FinancialDimensionTypeValue;
  /** Three-state: `undefined` = all, `true` = usable, `false` = retired. */
  is_active?: boolean;
  search?: string;
  per_page?: number;
  page?: number;
};

/** Client-side value-list filters; empty means "all". */
export type FinancialDimensionValueListFilters = {
  is_active?: boolean;
  search?: string;
  per_page?: number;
  page?: number;
};

/** Snapshot of a dimension list page. */
export type FinancialDimensionListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: FinancialDimension[];
  perPage: number;
  hasMore: boolean;
  message?: string;
  requestId?: string;
};

/** Snapshot of a single dimension. */
export type FinancialDimensionDetailState = {
  status: "loading" | "ready" | "error" | "unavailable" | "notfound";
  dimension: FinancialDimension | null;
  message?: string;
  requestId?: string;
};

/** Snapshot of one dimension's values. */
export type FinancialDimensionValueListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: FinancialDimensionValue[];
  perPage: number;
  hasMore: boolean;
  message?: string;
  requestId?: string;
};

/** Create body validated by `StoreFinancialDimensionRequest`. */
export type FinancialDimensionCreatePayload = {
  type: FinancialDimensionTypeValue;
  code: string;
  name: string;
};

/** Update body validated by `UpdateFinancialDimensionRequest` (type is fixed). */
export type FinancialDimensionUpdatePayload = Partial<{
  code: string;
  name: string;
}>;

/** Create/update body validated by the value request classes. */
export type FinancialDimensionValueWritePayload = {
  code: string;
  name: string;
};
