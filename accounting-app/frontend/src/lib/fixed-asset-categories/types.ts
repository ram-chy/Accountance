/**
 * Fixed asset categories data contracts.
 */

import type { AccountRef, DepreciationMethod } from "@/lib/fixed-assets/types";

export type FixedAssetCategory = {
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

export type FixedAssetCategoryListState = {
  status: "loading" | "ready" | "error" | "unavailable";
  items: FixedAssetCategory[];
  perPage: number;
  hasMore: boolean;
  message?: string;
  requestId?: string;
};

export type FixedAssetCategoryDetailState = {
  status: "loading" | "ready" | "error" | "unavailable" | "notfound";
  category: FixedAssetCategory | null;
  message?: string;
  requestId?: string;
};

export type FixedAssetCategoryListFilters = {
  is_active?: boolean;
  search?: string;
  per_page?: number;
  page?: number;
};

export type FixedAssetCategoryCreatePayload = {
  code: string;
  name: string;
  description: string | null;
  useful_life_months: number;
  depreciation_method?: DepreciationMethod;
  asset_account_id: number;
  accumulated_depreciation_account_id: number;
  depreciation_expense_account_id: number;
  gain_on_disposal_account_id: number | null;
  loss_on_disposal_account_id: number | null;
};

export type FixedAssetCategoryUpdatePayload = Partial<{
  code: string;
  name: string;
  description: string | null;
  useful_life_months: number;
  depreciation_method: DepreciationMethod;
  asset_account_id: number;
  accumulated_depreciation_account_id: number;
  depreciation_expense_account_id: number;
  gain_on_disposal_account_id: number | null;
  loss_on_disposal_account_id: number | null;
}>;
