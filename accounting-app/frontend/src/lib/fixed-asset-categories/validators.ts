import { normalizeList } from "@/lib/api/pagination";
import type { AccountRef } from "@/lib/fixed-assets/types";
import type {
  FixedAssetCategory,
  FixedAssetCategoryCreatePayload,
  FixedAssetCategoryDetailState,
  FixedAssetCategoryListState,
  FixedAssetCategoryUpdatePayload,
} from "./types";

export class FixedAssetCategoriesDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "FixedAssetCategoriesDataError";
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function asString(value: unknown): string | undefined {
  return typeof value === "string" ? value : undefined;
}

function asNullableString(value: unknown): string | null {
  return typeof value === "string" ? value : null;
}

function asNumber(value: unknown): number | undefined {
  return typeof value === "number" && Number.isFinite(value) ? value : undefined;
}

function asNullableNumber(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

function asBoolean(value: unknown): boolean | undefined {
  return typeof value === "boolean" ? value : undefined;
}

function parseAccountRef(value: unknown): AccountRef | null {
  if (value === null || value === undefined) return null;
  if (!isRecord(value)) return null;
  const id = asNumber(value.id);
  const code = asString(value.code);
  const name = asString(value.name);
  const account_type = asString(value.account_type);
  const is_active = asBoolean(value.is_active);
  if (id === undefined || code === undefined || name === undefined) return null;
  return {
    id,
    code,
    name,
    account_type: account_type ?? "UNKNOWN",
    is_active: is_active ?? true,
  };
}

export function parseFixedAssetCategory(value: unknown): FixedAssetCategory | null {
  if (!isRecord(value)) return null;
  const id = asNumber(value.id);
  const code = asString(value.code);
  const name = asString(value.name);
  const useful_life_months = asNumber(value.useful_life_months);
  const depreciation_method = asString(value.depreciation_method);
  const is_active = asBoolean(value.is_active);
  const asset_account_id = asNumber(value.asset_account_id);
  const accumulated_depreciation_account_id = asNumber(value.accumulated_depreciation_account_id);
  const depreciation_expense_account_id = asNumber(value.depreciation_expense_account_id);
  const gain_on_disposal_account_id = asNullableNumber(value.gain_on_disposal_account_id);
  const loss_on_disposal_account_id = asNullableNumber(value.loss_on_disposal_account_id);
  if (
    id === undefined ||
    code === undefined ||
    name === undefined ||
    useful_life_months === undefined ||
    depreciation_method === undefined ||
    is_active === undefined ||
    asset_account_id === undefined ||
    accumulated_depreciation_account_id === undefined ||
    depreciation_expense_account_id === undefined
  ) {
    return null;
  }
  if (depreciation_method !== "STRAIGHT_LINE") return null;
  return {
    id,
    code,
    name,
    description: asNullableString(value.description),
    useful_life_months,
    depreciation_method: "STRAIGHT_LINE" as const,
    is_active,
    asset_account_id,
    accumulated_depreciation_account_id,
    depreciation_expense_account_id,
    gain_on_disposal_account_id,
    loss_on_disposal_account_id,
    asset_account: parseAccountRef(value.asset_account),
    accumulated_depreciation_account: parseAccountRef(value.accumulated_depreciation_account),
    depreciation_expense_account: parseAccountRef(value.depreciation_expense_account),
    gain_on_disposal_account: parseAccountRef(value.gain_on_disposal_account),
    loss_on_disposal_account: parseAccountRef(value.loss_on_disposal_account),
    assets_count: asNullableNumber(value.assets_count) ?? undefined,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

export function parseFixedAssetCategoryOrThrow(raw: unknown, label = "category"): FixedAssetCategory {
  const c = parseFixedAssetCategory(raw);
  if (c === null) throw new FixedAssetCategoriesDataError(`The ${label} response was invalid.`);
  return c;
}

export function parseFixedAssetCategoryList(raw: unknown, perPage: number): FixedAssetCategoryListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return { status: "error", items: [], perPage, hasMore: false, message: "The category response was not a list." };
  }
  const list = normalizeList<unknown>(raw, perPage);
  const items: FixedAssetCategory[] = [];
  for (const e of list.items) {
    const c = parseFixedAssetCategory(e);
    if (c) items.push(c);
  }
  if (items.length === 0 && list.items.length > 0) {
    return { status: "error", items: [], perPage, hasMore: false, message: "The category response contained no valid rows." };
  }
  return { status: "ready", items, perPage, hasMore: list.hasMore };
}

export function parseFixedAssetCategoryDetail(raw: unknown): FixedAssetCategoryDetailState {
  const c = parseFixedAssetCategory(raw);
  if (c === null) return { status: "error", category: null, message: "The category response was invalid." };
  return { status: "ready", category: c };
}

export type CatValidationErrors = Record<string, string>;

export function validateFixedAssetCategoryCreate(payload: FixedAssetCategoryCreatePayload): CatValidationErrors {
  const errors: CatValidationErrors = {};
  if (!payload.code?.trim()) errors.code = "Code is required.";
  if (!payload.name?.trim()) errors.name = "Name is required.";
  if (payload.useful_life_months <= 0) errors.useful_life_months = "Useful life must be greater than 0.";
  if (payload.asset_account_id <= 0) errors.asset_account_id = "Asset account is required.";
  if (payload.accumulated_depreciation_account_id <= 0) errors.accumulated_depreciation_account_id = "Accumulated depreciation account is required.";
  if (payload.depreciation_expense_account_id <= 0) errors.depreciation_expense_account_id = "Depreciation expense account is required.";
  return errors;
}

export function validateFixedAssetCategoryUpdate(payload: FixedAssetCategoryUpdatePayload): CatValidationErrors {
  const errors: CatValidationErrors = {};
  if (payload.code !== undefined && !payload.code.trim()) errors.code = "Code is required.";
  if (payload.name !== undefined && !payload.name.trim()) errors.name = "Name is required.";
  if (payload.useful_life_months !== undefined && payload.useful_life_months <= 0) errors.useful_life_months = "Useful life must be greater than 0.";
  if (payload.asset_account_id !== undefined && payload.asset_account_id <= 0) errors.asset_account_id = "Invalid account.";
  if (payload.accumulated_depreciation_account_id !== undefined && payload.accumulated_depreciation_account_id <= 0) errors.accumulated_depreciation_account_id = "Invalid account.";
  if (payload.depreciation_expense_account_id !== undefined && payload.depreciation_expense_account_id <= 0) errors.depreciation_expense_account_id = "Invalid account.";
  return errors;
}
