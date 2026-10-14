/**
 * Fixed assets and related data contracts.
 *
 * These mirror the verified Laravel API: FixedAssetResource, FixedAssetDepreciationResource,
 * FixedAssetDisposalResource and the depreciation schedule/report shapes. All monetary
 * values are decimal strings. Identifiers and audit columns are server-owned.
 */

export type FixedAssetStatus = "DRAFT" | "ACTIVE" | "FULLY_DEPRECIATED" | "DISPOSED";

export const FIXED_ASSET_STATUSES: readonly FixedAssetStatus[] = [
  "DRAFT",
  "ACTIVE",
  "FULLY_DEPRECIATED",
  "DISPOSED",
] as const;

export type FixedAssetAcquisitionMethod = "CASH" | "SUPPLIER_CREDIT";

export const FIXED_ASSET_ACQUISITION_METHODS: readonly FixedAssetAcquisitionMethod[] = [
  "CASH",
  "SUPPLIER_CREDIT",
] as const;

export type DepreciationMethod = "STRAIGHT_LINE";

export const DEPRECIATION_METHODS: readonly DepreciationMethod[] = ["STRAIGHT_LINE"] as const;

/** Compact account reference (from AccountResource). */
export type AccountRef = {
  id: number;
  code: string;
  name: string;
  account_type: string;
  is_active: boolean;
};

/** Fixed asset category reference when embedded. */
export type FixedAssetCategoryRef = {
  id: number;
  code: string;
  name: string;
  description: string | null;
  useful_life_months: number;
  depreciation_method: DepreciationMethod;
  is_active: boolean;
  asset_account_id: number;
  accumulated_depreciation_account_id: number;
  depreciation_expense_account_id: number;
  gain_on_disposal_account_id: number | null;
  loss_on_disposal_account_id: number | null;
  asset_account: AccountRef | null;
  accumulated_depreciation_account: AccountRef | null;
  depreciation_expense_account: AccountRef | null;
  gain_on_disposal_account: AccountRef | null;
  loss_on_disposal_account: AccountRef | null;
  assets_count?: number | null;
  created_at: string | null;
  updated_at: string | null;
};

export type FixedAssetDepreciation = {
  id: number;
  fixed_asset_id: number;
  period_number: number;
  period_start_date: string;
  period_end_date: string;
  amount: string;
  is_final_period: boolean;
  journal_id: number | null;
  journal: JournalRef | null;
  posted_at: string | null;
  posted_by: number | null;
  created_at: string | null;
};

export type FixedAssetDisposal = {
  id: number;
  fixed_asset_id: number;
  disposal_date: string;
  reason: string | null;
  proceeds: string;
  carrying_value_at_disposal: string;
  gain: string;
  loss: string;
  net_result: string;
  proceeds_account_id: number;
  proceeds_account: AccountRef | null;
  journal_id: number | null;
  journal: JournalRef | null;
  posted_at: string | null;
  disposed_by: number | null;
  created_at: string | null;
};

export type JournalRef = {
  id: number;
  journal_number: string;
  journal_date: string;
  reference: string | null;
  status: string;
  source_type: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type FixedAsset = {
  id: number;
  asset_number: string;
  name: string;
  description: string | null;
  serial_number: string | null;
  supplier_reference: string | null;
  status: FixedAssetStatus;
  acquisition_method: FixedAssetAcquisitionMethod;
  fixed_asset_category_id: number;
  category: FixedAssetCategoryRef | null;
  acquisition_date: string;
  depreciation_start_date: string;
  original_cost: string;
  salvage_value: string;
  useful_life_months: number;
  depreciation_method: DepreciationMethod;
  accumulated_depreciation: string;
  carrying_value: string;
  monthly_charge: string;
  period_count: number;
  remaining_period_count: number;
  asset_account_id: number;
  accumulated_depreciation_account_id: number;
  depreciation_expense_account_id: number;
  gain_on_disposal_account_id: number | null;
  loss_on_disposal_account_id: number | null;
  acquisition_account_id: number;
  asset_account: AccountRef | null;
  accumulated_depreciation_account: AccountRef | null;
  depreciation_expense_account: AccountRef | null;
  acquisition_account: AccountRef | null;
  journal_id: number | null;
  journal: JournalRef | null;
  capitalised_at: string | null;
  fully_depreciated_at: string | null;
  disposed_at: string | null;
  depreciations: FixedAssetDepreciation[] | null;
  disposal: FixedAssetDisposal | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string | null;
  updated_at: string | null;
};

export type FixedAssetListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: FixedAsset[];
  perPage: number;
  hasMore: boolean;
  message?: string;
  requestId?: string;
};

export type FixedAssetDetailState = {
  status: "loading" | "ready" | "error" | "unavailable" | "notfound";
  asset: FixedAsset | null;
  message?: string;
  requestId?: string;
};

export type FixedAssetListFilters = {
  status?: FixedAssetStatus;
  acquisition_method?: FixedAssetAcquisitionMethod;
  fixed_asset_category_id?: number;
  from?: string;
  to?: string;
  search?: string;
  per_page?: number;
  page?: number;
};

export type FixedAssetCreateCashPayload = {
  fixed_asset_category_id: number;
  name: string;
  description: string | null;
  serial_number: string | null;
  supplier_reference: string | null;
  acquisition_date: string;
  depreciation_start_date: string;
  original_cost: string;
  salvage_value: string | null;
  acquisition_account_id: number;
};

export type FixedAssetCreateSupplierCreditPayload = FixedAssetCreateCashPayload;

export type FixedAssetUpdatePayload = Partial<{
  fixed_asset_category_id: number;
  name: string;
  description: string | null;
  serial_number: string | null;
  supplier_reference: string | null;
  acquisition_date: string;
  depreciation_start_date: string;
  original_cost: string;
  salvage_value: string | null;
  acquisition_account_id: number;
}>;

export type DisposeFixedAssetPayload = {
  disposal_date: string;
  proceeds: string | null;
  reason: string | null;
  proceeds_account_id: number;
};

export type DepreciationSchedulePeriod = {
  period_number: number;
  period_start_date: string;
  period_end_date: string;
  posting_date: string;
  amount: string;
  is_final_period: boolean;
};

export type DepreciationScheduleResponse = {
  fixed_asset: FixedAsset;
  upcoming_periods: DepreciationSchedulePeriod[];
};

export type DepreciateResponse = {
  depreciation: FixedAssetDepreciation;
  fixed_asset: FixedAsset;
};

export type DisposeResponse = {
  disposal: FixedAssetDisposal;
  fixed_asset: FixedAsset;
};

export type DepreciationReportAsset = {
  fixed_asset_id: number;
  asset_number: string;
  name: string;
  category: string | null;
  period_count: number;
  total_depreciation: string;
  entries: FixedAssetDepreciation[];
};

export type DepreciationReport = {
  from: string;
  to: string;
  total_depreciation: string;
  assets: DepreciationReportAsset[];
};
