/**
 * Tax, tax-rate, account-mapping, calculation and report data contracts.
 *
 * These mirror the verified Laravel `TaxResource`, `TaxRateResource`,
 * `TaxAccountMappingResource`, `TaxCalculationResource` and the
 * `TaxReportService` payloads exactly (see `TaxController.php`,
 * `TaxReportController.php`, `TaxReportService.php` and
 * `FRONTEND_API_HANDOFF.md`). Every monetary and percentage value is a decimal
 * **string** — the backend never sends a rate or amount as a JSON number, because
 * a double cannot round-trip `7.5000` exactly.
 *
 * The tax master is company-scoped (no global taxes). Write payloads are written
 * in the snake_case the backend validates; identifiers and audit columns are
 * server-owned, and `company_id` is never sent — the active company comes from
 * the server-side proxy context.
 */

/** Which side of a transaction a tax applies to (`App\Enums\TaxType`). */
export type TaxTypeValue = "OUTPUT" | "INPUT" | "BOTH";

export const TAX_TYPE_VALUES: readonly TaxTypeValue[] = [
  "OUTPUT",
  "INPUT",
  "BOTH",
] as const;

export const TAX_TYPE_LABELS: Record<TaxTypeValue, string> = {
  OUTPUT: "Output — charged on sales",
  INPUT: "Input — recovered on purchases",
  BOTH: "Both — sales and purchases",
};

/** Whether a submitted amount is net or gross (`App\Enums\TaxCalculationBasis`). */
export type TaxCalculationBasisValue = "EXCLUSIVE" | "INCLUSIVE";

export const TAX_CALCULATION_BASIS_VALUES: readonly TaxCalculationBasisValue[] = [
  "EXCLUSIVE",
  "INCLUSIVE",
] as const;

export const TAX_CALCULATION_BASIS_LABELS: Record<
  TaxCalculationBasisValue,
  string
> = {
  EXCLUSIVE: "Exclusive — the amount does not include tax",
  INCLUSIVE: "Inclusive — the amount already includes tax",
};

/** The compact account reference embedded in a mapping (`TaxAccountMappingResource`). */
export type TaxAccountRef = {
  id: number;
  code: string;
  name: string;
  account_type: string;
  is_active: boolean;
};

/**
 * A tax's account mapping as serialised by `TaxAccountMappingResource`.
 *
 * Rendered even when the tax has never been mapped: every field is null in that
 * case, which is how the API reports "not configured" without a 404.
 */
export type TaxAccountMapping = {
  tax_id: number | null;
  output_account_id: number | null;
  input_account_id: number | null;
  output_account: TaxAccountRef | null;
  input_account: TaxAccountRef | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string | null;
  updated_at: string | null;
};

/** One row from the verified `TaxResource`. */
export type Tax = {
  id: number;
  code: string;
  name: string;
  description: string | null;
  tax_type: TaxTypeValue;
  calculation_basis: TaxCalculationBasisValue;
  is_active: boolean;
  /** Derived by the backend; true when the tax applies on the sales side. */
  applies_to_sales: boolean;
  /** Derived by the backend; true when the tax applies on the purchase side. */
  applies_to_purchase: boolean;
  /** The rate covering today, as a percentage string, or null when none does. */
  current_rate: string | null;
  account_mapping: TaxAccountMapping | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string | null;
  updated_at: string | null;
};

/** Client-side list filter; the only filter the index endpoint supports. */
export type TaxListFilters = {
  /** When true, request only active taxes (`?active_only=1`). */
  active_only: boolean;
};

export const DEFAULT_TAX_LIST_FILTERS: TaxListFilters = {
  active_only: false,
};

/** Snapshot of the (unpaginated) tax list. */
export type TaxListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: Tax[];
  message?: string;
  requestId?: string;
};

/** A single tax detail snapshot. */
export type TaxDetailState = {
  status: "loading" | "ready" | "error" | "unavailable" | "notfound";
  tax: Tax | null;
  message?: string;
  requestId?: string;
};

/** One row from the verified `TaxRateResource`. */
export type TaxRate = {
  id: number;
  tax_id: number;
  /** Decimal percentage string, sent back exactly as received. */
  rate: string;
  effective_from: string;
  effective_to: string | null;
  is_open_ended: boolean;
  is_active: boolean;
  created_by: number | null;
  updated_by: number | null;
  created_at: string | null;
  updated_at: string | null;
};

/** Snapshot of one tax's rate history. */
export type TaxRateListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: TaxRate[];
  message?: string;
  requestId?: string;
};

/** Create body validated by `StoreTaxRequest`. */
export type TaxCreatePayload = {
  code: string;
  name: string;
  description: string | null;
  tax_type: TaxTypeValue;
  calculation_basis: TaxCalculationBasisValue;
};

/** Update body validated by `UpdateTaxRequest` (PUT; absent means unchanged). */
export type TaxUpdatePayload = {
  code?: string;
  name?: string;
  description?: string | null;
  tax_type?: TaxTypeValue;
  calculation_basis?: TaxCalculationBasisValue;
};

/** Create body validated by `StoreTaxRateRequest`. */
export type TaxRateCreatePayload = {
  rate: string;
  effective_from: string;
  effective_to: string | null;
};

/** Update body validated by `UpdateTaxRateRequest`. */
export type TaxRateUpdatePayload = {
  rate?: string;
  effective_from?: string;
  effective_to?: string | null;
};

/** Body validated by `UpdateTaxAccountMappingRequest` (PUT, full replacement). */
export type TaxAccountMappingPayload = {
  output_account_id: number | null;
  input_account_id: number | null;
};

/** One component of a `TaxCalculationResource` response. */
export type TaxCalculationComponent = {
  tax_id: number;
  tax_code: string;
  tax_name: string;
  tax_type: string;
  tax_rate_id: number;
  rate: string;
  taxable_amount: string;
  tax_amount: string;
  output_account_id: number | null;
  input_account_id: number | null;
};

/** The verified `POST /accounting/tax/calculate` response. */
export type TaxCalculation = {
  basis: TaxCalculationBasisValue;
  taxable_amount: string;
  total_tax: string;
  gross_amount: string;
  tax_components: TaxCalculationComponent[];
};

/** Request body for the read-only calculation endpoint (validated by `CalculateTaxRequest`). */
export type TaxCalculationRequest = {
  amount: string;
  date: string;
  tax_ids?: number[];
  basis?: TaxCalculationBasisValue;
};

/** One row of a tax report (`TaxReportService::renderRow()`). */
export type TaxReportRow = {
  tax_id: number | null;
  tax_code: string | null;
  tax_name: string | null;
  tax_type: string | null;
  sales_taxable: string;
  output_tax: string;
  purchase_taxable: string;
  input_tax: string;
  net_tax: string;
  unattributed_tax: string;
};

/** The report totals block. */
export type TaxReportTotals = {
  sales_taxable: string;
  output_tax: string;
  purchase_taxable: string;
  input_tax: string;
  net_tax: string;
  unattributed_tax: string;
};

/** The base-currency disclosure block (present on the by-tax report only). */
export type TaxReportBaseCurrency = {
  code: string | null;
  name: string | null;
  symbol: string | null;
  decimals: number | null;
};

/** The verified summary / by-tax report payload. */
export type TaxReport = {
  period: { from: string | null; to: string | null };
  base_currency?: TaxReportBaseCurrency;
  rows: TaxReportRow[];
  totals: TaxReportTotals;
};

/** Snapshot of both tax reports for a single date window. */
export type TaxReportState = {
  status: "loading" | "ready" | "error" | "unavailable";
  summary: TaxReport | null;
  byTax: TaxReport | null;
  message?: string;
  requestId?: string;
};

export type TaxRangeFilters = {
  from: string;
  to: string;
};

export const DEFAULT_TAX_RANGE_FILTERS: TaxRangeFilters = {
  from: "",
  to: "",
};
