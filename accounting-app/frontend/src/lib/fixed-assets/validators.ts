import { normalizeList } from "@/lib/api/pagination";
import type {
  DepreciateResponse,
  DepreciationReport,
  DepreciationReportAsset,
  DepreciationSchedulePeriod,
  DepreciationScheduleResponse,
  DisposeResponse,
  FixedAsset,
  FixedAssetCreateCashPayload,
  FixedAssetCreateSupplierCreditPayload,
  FixedAssetDepreciation,
  FixedAssetDetailState,
  FixedAssetDisposal,
  FixedAssetListState,
  FixedAssetUpdatePayload,
  DisposeFixedAssetPayload,
  AccountRef,
  FixedAssetCategoryRef,
  JournalRef,
} from "./types";

export class FixedAssetsDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "FixedAssetsDataError";
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
  return { id, code, name, account_type: account_type ?? "UNKNOWN", is_active: is_active ?? true };
}

function parseCategoryRef(value: unknown): FixedAssetCategoryRef | null {
  if (value === null || value === undefined) return null;
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
  ) return null;
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

function parseJournalRef(value: unknown): JournalRef | null {
  if (value === null || value === undefined) return null;
  if (!isRecord(value)) return null;
  const id = asNumber(value.id);
  const journal_number = asString(value.journal_number);
  const journal_date = asString(value.journal_date);
  const status = asString(value.status);
  if (id === undefined || journal_number === undefined || journal_date === undefined || status === undefined) return null;
  return {
    id,
    journal_number,
    journal_date,
    reference: asNullableString(value.reference),
    status,
    source_type: asNullableString(value.source_type),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

export function parseFixedAssetDepreciation(value: unknown): FixedAssetDepreciation | null {
  if (!isRecord(value)) return null;
  const id = asNumber(value.id);
  const fixed_asset_id = asNumber(value.fixed_asset_id);
  const period_number = asNumber(value.period_number);
  const period_start_date = asString(value.period_start_date);
  const period_end_date = asString(value.period_end_date);
  const amount = asString(value.amount);
  const is_final_period = asBoolean(value.is_final_period);
  if (
    id === undefined ||
    fixed_asset_id === undefined ||
    period_number === undefined ||
    period_start_date === undefined ||
    period_end_date === undefined ||
    amount === undefined ||
    is_final_period === undefined
  ) return null;
  return {
    id,
    fixed_asset_id,
    period_number,
    period_start_date,
    period_end_date,
    amount,
    is_final_period,
    journal_id: asNullableNumber(value.journal_id),
    journal: parseJournalRef(value.journal),
    posted_at: asNullableString(value.posted_at),
    posted_by: asNullableNumber(value.posted_by),
    created_at: asNullableString(value.created_at),
  };
}

export function parseFixedAssetDisposal(value: unknown): FixedAssetDisposal | null {
  if (!isRecord(value)) return null;
  const id = asNumber(value.id);
  const fixed_asset_id = asNumber(value.fixed_asset_id);
  const disposal_date = asString(value.disposal_date);
  const proceeds = asString(value.proceeds);
  const carrying_value_at_disposal = asString(value.carrying_value_at_disposal);
  const gain = asString(value.gain);
  const loss = asString(value.loss);
  const net_result = asString(value.net_result);
  const proceeds_account_id = asNumber(value.proceeds_account_id);
  if (
    id === undefined ||
    fixed_asset_id === undefined ||
    disposal_date === undefined ||
    proceeds === undefined ||
    carrying_value_at_disposal === undefined ||
    gain === undefined ||
    loss === undefined ||
    net_result === undefined ||
    proceeds_account_id === undefined
  ) return null;
  return {
    id,
    fixed_asset_id,
    disposal_date,
    reason: asNullableString(value.reason),
    proceeds,
    carrying_value_at_disposal,
    gain,
    loss,
    net_result,
    proceeds_account_id,
    proceeds_account: parseAccountRef(value.proceeds_account),
    journal_id: asNullableNumber(value.journal_id),
    journal: parseJournalRef(value.journal),
    posted_at: asNullableString(value.posted_at),
    disposed_by: asNullableNumber(value.disposed_by),
    created_at: asNullableString(value.created_at),
  };
}

export function parseFixedAsset(value: unknown): FixedAsset | null {
  if (!isRecord(value)) return null;
  const id = asNumber(value.id);
  const asset_number = asString(value.asset_number);
  const name = asString(value.name);
  const status = asString(value.status);
  const acquisition_method = asString(value.acquisition_method);
  const fixed_asset_category_id = asNumber(value.fixed_asset_category_id);
  const acquisition_date = asString(value.acquisition_date);
  const depreciation_start_date = asString(value.depreciation_start_date);
  const original_cost = asString(value.original_cost);
  const salvage_value = asString(value.salvage_value);
  const useful_life_months = asNumber(value.useful_life_months);
  const depreciation_method = asString(value.depreciation_method);
  const accumulated_depreciation = asString(value.accumulated_depreciation);
  const carrying_value = asString(value.carrying_value);
  const monthly_charge = asString(value.monthly_charge);
  const period_count = asNumber(value.period_count);
  const remaining_period_count = asNumber(value.remaining_period_count);
  const asset_account_id = asNumber(value.asset_account_id);
  const accumulated_depreciation_account_id = asNumber(value.accumulated_depreciation_account_id);
  const depreciation_expense_account_id = asNumber(value.depreciation_expense_account_id);
  const acquisition_account_id = asNumber(value.acquisition_account_id);
  if (
    id === undefined ||
    asset_number === undefined ||
    name === undefined ||
    status === undefined ||
    acquisition_method === undefined ||
    fixed_asset_category_id === undefined ||
    acquisition_date === undefined ||
    depreciation_start_date === undefined ||
    original_cost === undefined ||
    salvage_value === undefined ||
    useful_life_months === undefined ||
    depreciation_method === undefined ||
    accumulated_depreciation === undefined ||
    carrying_value === undefined ||
    monthly_charge === undefined ||
    period_count === undefined ||
    remaining_period_count === undefined ||
    asset_account_id === undefined ||
    accumulated_depreciation_account_id === undefined ||
    depreciation_expense_account_id === undefined ||
    acquisition_account_id === undefined
  ) return null;
  if (status !== "DRAFT" && status !== "ACTIVE" && status !== "FULLY_DEPRECIATED" && status !== "DISPOSED") return null;
  if (acquisition_method !== "CASH" && acquisition_method !== "SUPPLIER_CREDIT") return null;
  if (depreciation_method !== "STRAIGHT_LINE") return null;
  const depreciations: FixedAssetDepreciation[] = [];
  if (Array.isArray(value.depreciations)) {
    for (const entry of value.depreciations) {
      const d = parseFixedAssetDepreciation(entry);
      if (d) depreciations.push(d);
    }
  }
  return {
    id,
    asset_number,
    name,
    description: asNullableString(value.description),
    serial_number: asNullableString(value.serial_number),
    supplier_reference: asNullableString(value.supplier_reference),
    status,
    acquisition_method,
    fixed_asset_category_id,
    category: parseCategoryRef(value.category),
    acquisition_date,
    depreciation_start_date,
    original_cost,
    salvage_value,
    useful_life_months,
    depreciation_method: "STRAIGHT_LINE" as const,
    accumulated_depreciation,
    carrying_value,
    monthly_charge,
    period_count,
    remaining_period_count,
    asset_account_id,
    accumulated_depreciation_account_id,
    depreciation_expense_account_id,
    gain_on_disposal_account_id: asNullableNumber(value.gain_on_disposal_account_id),
    loss_on_disposal_account_id: asNullableNumber(value.loss_on_disposal_account_id),
    acquisition_account_id,
    asset_account: parseAccountRef(value.asset_account),
    accumulated_depreciation_account: parseAccountRef(value.accumulated_depreciation_account),
    depreciation_expense_account: parseAccountRef(value.depreciation_expense_account),
    acquisition_account: parseAccountRef(value.acquisition_account),
    journal_id: asNullableNumber(value.journal_id),
    journal: parseJournalRef(value.journal),
    capitalised_at: asNullableString(value.capitalised_at),
    fully_depreciated_at: asNullableString(value.fully_depreciated_at),
    disposed_at: asNullableString(value.disposed_at),
    depreciations: depreciations.length > 0 || Array.isArray(value.depreciations) ? depreciations : null,
    disposal: parseFixedAssetDisposal(value.disposal),
    created_by: asNullableNumber(value.created_by),
    updated_by: asNullableNumber(value.updated_by),
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

function toListErrorState(perPage: number, message: string): FixedAssetListState {
  return { status: "error", items: [], perPage, hasMore: false, message };
}
function toDetailErrorState(message: string): FixedAssetDetailState {
  return { status: "error", asset: null, message };
}

export function parseFixedAssetList(raw: unknown, perPage: number): FixedAssetListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return toListErrorState(perPage, "The fixed assets response was not a list.");
  }
  const list = normalizeList<unknown>(raw, perPage);
  const items: FixedAsset[] = [];
  for (const entry of list.items) {
    const a = parseFixedAsset(entry);
    if (a) items.push(a);
  }
  if (items.length === 0 && list.items.length > 0) {
    return toListErrorState(perPage, "The fixed assets response contained no valid asset rows.");
  }
  return { status: "ready", items, perPage, hasMore: list.hasMore };
}

export function parseFixedAssetOrThrow(raw: unknown, label = "fixed asset"): FixedAsset {
  const a = parseFixedAsset(raw);
  if (a === null) throw new FixedAssetsDataError(`The ${label} response was invalid.`);
  return a;
}

export function parseFixedAssetDetail(raw: unknown): FixedAssetDetailState {
  const a = parseFixedAsset(raw);
  if (a === null) return toDetailErrorState("The fixed asset response was invalid.");
  return { status: "ready", asset: a };
}

export function parseDepreciationSchedulePeriod(value: unknown): DepreciationSchedulePeriod | null {
  if (!isRecord(value)) return null;
  const period_number = asNumber(value.period_number);
  const period_start_date = asString(value.period_start_date);
  const period_end_date = asString(value.period_end_date);
  const posting_date = asString(value.posting_date);
  const amount = asString(value.amount);
  const is_final_period = asBoolean(value.is_final_period);
  if (period_number === undefined || period_start_date === undefined || period_end_date === undefined || posting_date === undefined || amount === undefined || is_final_period === undefined) return null;
  return { period_number, period_start_date, period_end_date, posting_date, amount, is_final_period };
}

export function parseDepreciationScheduleResponse(raw: unknown): DepreciationScheduleResponse | null {
  if (!isRecord(raw)) return null;
  const data = raw.data ?? raw;
  if (!isRecord(data)) return null;
  const fa = parseFixedAsset(data.fixed_asset);
  if (fa === null) return null;
  const periods: DepreciationSchedulePeriod[] = [];
  const up = data.upcoming_periods ?? data.periods;
  if (Array.isArray(up)) {
    for (const e of up) {
      const p = parseDepreciationSchedulePeriod(e);
      if (p) periods.push(p);
    }
  }
  return { fixed_asset: fa, upcoming_periods: periods };
}

export function parseDepreciateResponse(raw: unknown): DepreciateResponse | null {
  if (!isRecord(raw)) return null;
  const data = raw.data ?? raw;
  if (!isRecord(data)) return null;
  const dep = parseFixedAssetDepreciation(data.depreciation);
  const fa = parseFixedAsset(data.fixed_asset);
  if (dep === null || fa === null) return null;
  return { depreciation: dep, fixed_asset: fa };
}

export function parseDisposeResponse(raw: unknown): DisposeResponse | null {
  if (!isRecord(raw)) return null;
  const data = raw.data ?? raw;
  if (!isRecord(data)) return null;
  const dis = parseFixedAssetDisposal(data.disposal);
  const fa = parseFixedAsset(data.fixed_asset);
  if (dis === null || fa === null) return null;
  return { disposal: dis, fixed_asset: fa };
}

export function parseDepreciationReportAsset(value: unknown): DepreciationReportAsset | null {
  if (!isRecord(value)) return null;
  const fixed_asset_id = asNumber(value.fixed_asset_id);
  const asset_number = asString(value.asset_number);
  const name = asString(value.name);
  const category = asNullableString(value.category);
  const period_count = asNumber(value.period_count);
  const total_depreciation = asString(value.total_depreciation);
  if (fixed_asset_id === undefined || asset_number === undefined || name === undefined || period_count === undefined || total_depreciation === undefined) return null;
  const entries: FixedAssetDepreciation[] = [];
  if (Array.isArray(value.entries)) {
    for (const e of value.entries) {
      const p = parseFixedAssetDepreciation(e);
      if (p) entries.push(p);
    }
  }
  return { fixed_asset_id, asset_number, name, category, period_count, total_depreciation, entries };
}

export function parseDepreciationReport(raw: unknown): DepreciationReport | null {
  if (!isRecord(raw)) return null;
  const data = raw.data ?? raw;
  if (!isRecord(data)) return null;
  const from = asString(data.from);
  const to = asString(data.to);
  const total_depreciation = asString(data.total_depreciation);
  if (from === undefined || to === undefined || total_depreciation === undefined) return null;
  const assets: DepreciationReportAsset[] = [];
  if (Array.isArray(data.assets)) {
    for (const e of data.assets) {
      const a = parseDepreciationReportAsset(e);
      if (a) assets.push(a);
    }
  }
  return { from, to, total_depreciation, assets };
}

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

export type FixedAssetCashValidationErrors = Record<string, string>;
export function validateFixedAssetCash(payload: FixedAssetCreateCashPayload): FixedAssetCashValidationErrors {
  const errors: FixedAssetCashValidationErrors = {};
  if (!payload.name?.trim()) errors.name = "Asset name is required.";
  if (payload.fixed_asset_category_id <= 0) errors.fixed_asset_category_id = "Category is required.";
  if (!DATE_PATTERN.test(payload.acquisition_date)) errors.acquisition_date = "Invalid acquisition date.";
  if (!DATE_PATTERN.test(payload.depreciation_start_date)) errors.depreciation_start_date = "Invalid depreciation start date.";
  if (!payload.original_cost || Number(payload.original_cost) <= 0) errors.original_cost = "Original cost must be greater than 0.";
  if (payload.acquisition_account_id <= 0) errors.acquisition_account_id = "Acquisition account is required.";
  return errors;
}

export function validateFixedAssetSupplierCredit(payload: FixedAssetCreateSupplierCreditPayload): FixedAssetCashValidationErrors {
  return validateFixedAssetCash(payload);
}

export type FixedAssetUpdateValidationErrors = Record<string, string>;
export function validateFixedAssetUpdate(payload: FixedAssetUpdatePayload): FixedAssetUpdateValidationErrors {
  const errors: FixedAssetUpdateValidationErrors = {};
  if (payload.name !== undefined && !payload.name.trim()) errors.name = "Asset name is required.";
  if (payload.fixed_asset_category_id !== undefined && payload.fixed_asset_category_id <= 0) errors.fixed_asset_category_id = "Invalid category.";
  if (payload.acquisition_date !== undefined && !DATE_PATTERN.test(payload.acquisition_date)) errors.acquisition_date = "Invalid acquisition date.";
  if (payload.depreciation_start_date !== undefined && !DATE_PATTERN.test(payload.depreciation_start_date)) errors.depreciation_start_date = "Invalid depreciation start date.";
  if (payload.original_cost !== undefined && (!payload.original_cost || Number(payload.original_cost) <= 0)) errors.original_cost = "Original cost must be greater than 0.";
  if (payload.acquisition_account_id !== undefined && payload.acquisition_account_id <= 0) errors.acquisition_account_id = "Invalid acquisition account.";
  return errors;
}

export type DisposeValidationErrors = Record<string, string>;
export function validateDispose(payload: DisposeFixedAssetPayload): DisposeValidationErrors {
  const errors: DisposeValidationErrors = {};
  if (!DATE_PATTERN.test(payload.disposal_date)) errors.disposal_date = "Invalid disposal date.";
  if (payload.proceeds !== null && payload.proceeds !== undefined && payload.proceeds !== "" && Number(payload.proceeds) < 0) errors.proceeds = "Proceeds cannot be negative.";
  if (payload.proceeds_account_id <= 0) errors.proceeds_account_id = "Proceeds account is required.";
  return errors;
}
