/**
 * Runtime validation for financial-dimension responses and inputs.
 *
 * API JSON is untrusted input. The parsers below narrow a raw payload to the
 * exact fields the UI renders. A row missing a required identifier, code/name or
 * a known type — or carrying a type the backend does not implement — is treated
 * as invalid and skipped, the same "filter the invalid row, never fabricate"
 * strategy the fixed-asset-category and period validators use.
 */

import { normalizeList } from "@/lib/api/pagination";

import {
  FINANCIAL_DIMENSION_TYPE_VALUES,
  type FinancialDimension,
  type FinancialDimensionCreatePayload,
  type FinancialDimensionDetailState,
  type FinancialDimensionListState,
  type FinancialDimensionTypeValue,
  type FinancialDimensionUpdatePayload,
  type FinancialDimensionValue,
  type FinancialDimensionValueListState,
  type FinancialDimensionValueWritePayload,
} from "./types";

export class DimensionsDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "DimensionsDataError";
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

function asDimensionType(value: unknown): FinancialDimensionTypeValue | undefined {
  return FINANCIAL_DIMENSION_TYPE_VALUES.includes(value as FinancialDimensionTypeValue)
    ? (value as FinancialDimensionTypeValue)
    : undefined;
}

export function parseFinancialDimensionValue(value: unknown): FinancialDimensionValue | null {
  if (!isRecord(value)) return null;

  const id = asNumber(value.id);
  const financial_dimension_id = asNumber(value.financial_dimension_id);
  const code = asString(value.code);
  const name = asString(value.name);
  const is_active = asBoolean(value.is_active);

  if (
    id === undefined ||
    financial_dimension_id === undefined ||
    code === undefined ||
    name === undefined ||
    is_active === undefined
  ) {
    return null;
  }

  return {
    id,
    financial_dimension_id,
    code,
    name,
    is_active,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

export function parseFinancialDimension(value: unknown): FinancialDimension | null {
  if (!isRecord(value)) return null;

  const id = asNumber(value.id);
  const type = asDimensionType(value.type);
  const code = asString(value.code);
  const name = asString(value.name);
  const is_active = asBoolean(value.is_active);

  if (id === undefined || type === undefined || code === undefined || name === undefined || is_active === undefined) {
    return null;
  }

  let values: FinancialDimensionValue[] | undefined;
  if (Array.isArray(value.values)) {
    values = [];
    for (const entry of value.values) {
      const parsed = parseFinancialDimensionValue(entry);
      if (parsed !== null) values.push(parsed);
    }
  }

  return {
    id,
    type,
    type_label: asString(value.type_label) ?? "",
    code,
    name,
    is_active,
    values,
    values_count: asNullableNumber(value.values_count) ?? undefined,
    created_at: asNullableString(value.created_at),
    updated_at: asNullableString(value.updated_at),
  };
}

export function parseFinancialDimensionOrThrow(raw: unknown, label = "dimension"): FinancialDimension {
  const dimension = parseFinancialDimension(raw);
  if (dimension === null) {
    throw new DimensionsDataError(`The ${label} response was invalid.`);
  }
  return dimension;
}

export function parseFinancialDimensionValueOrThrow(
  raw: unknown,
  label = "dimension value",
): FinancialDimensionValue {
  const value = parseFinancialDimensionValue(raw);
  if (value === null) {
    throw new DimensionsDataError(`The ${label} response was invalid.`);
  }
  return value;
}

export function parseFinancialDimensionList(raw: unknown, perPage: number): FinancialDimensionListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return { status: "error", items: [], perPage, hasMore: false, message: "The dimension response was not a list." };
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: FinancialDimension[] = [];

  for (const entry of list.items) {
    const dimension = parseFinancialDimension(entry);
    if (dimension !== null) items.push(dimension);
  }

  if (items.length === 0 && list.items.length > 0) {
    return {
      status: "error",
      items: [],
      perPage,
      hasMore: false,
      message: "The dimension response contained no valid rows.",
    };
  }

  return { status: "ready", items, perPage, hasMore: list.hasMore };
}

export function parseFinancialDimensionDetail(raw: unknown): FinancialDimensionDetailState {
  const dimension = parseFinancialDimension(raw);
  if (dimension === null) {
    return { status: "error", dimension: null, message: "The dimension response was invalid." };
  }
  return { status: "ready", dimension };
}

export function parseFinancialDimensionValueList(raw: unknown, perPage: number): FinancialDimensionValueListState {
  if (!isRecord(raw) && !Array.isArray(raw)) {
    return { status: "error", items: [], perPage, hasMore: false, message: "The value response was not a list." };
  }

  const list = normalizeList<unknown>(raw, perPage);
  const items: FinancialDimensionValue[] = [];

  for (const entry of list.items) {
    const value = parseFinancialDimensionValue(entry);
    if (value !== null) items.push(value);
  }

  if (items.length === 0 && list.items.length > 0) {
    return {
      status: "error",
      items: [],
      perPage,
      hasMore: false,
      message: "The value response contained no valid rows.",
    };
  }

  return { status: "ready", items, perPage, hasMore: list.hasMore };
}

export type DimensionValidationErrors = Record<string, string>;

export function validateFinancialDimensionCreate(
  payload: FinancialDimensionCreatePayload,
): DimensionValidationErrors {
  const errors: DimensionValidationErrors = {};

  if (!payload.code?.trim()) errors.code = "Code is required.";
  if (!payload.name?.trim()) errors.name = "Name is required.";
  if (!payload.type) errors.type = "Type is required.";

  return errors;
}

export function validateFinancialDimensionUpdate(
  payload: FinancialDimensionUpdatePayload,
): DimensionValidationErrors {
  const errors: DimensionValidationErrors = {};

  if (payload.code !== undefined && !payload.code.trim()) errors.code = "Code is required.";
  if (payload.name !== undefined && !payload.name.trim()) errors.name = "Name is required.";

  return errors;
}

export function validateFinancialDimensionValueWrite(
  payload: FinancialDimensionValueWritePayload,
): DimensionValidationErrors {
  const errors: DimensionValidationErrors = {};

  if (!payload.code?.trim()) errors.code = "Code is required.";
  if (!payload.name?.trim()) errors.name = "Name is required.";

  return errors;
}
