import { describe, expect, it } from "vitest";

import type { FixedAssetCategory, FixedAssetCategoryCreatePayload } from "./types";
import {
  parseFixedAssetCategory,
  parseFixedAssetCategoryList,
  parseFixedAssetCategoryOrThrow,
  validateFixedAssetCategoryCreate,
  validateFixedAssetCategoryUpdate,
} from "./validators";

const account = (id: number, code: string, name: string) => ({
  id,
  code,
  name,
  account_type: "ASSET",
  is_active: true,
});

const validCategory: FixedAssetCategory = {
  id: 3,
  code: "MOTOR",
  name: "Motor vehicles",
  description: null,
  useful_life_months: 60,
  depreciation_method: "STRAIGHT_LINE",
  is_active: true,
  asset_account_id: 10,
  accumulated_depreciation_account_id: 11,
  depreciation_expense_account_id: 12,
  gain_on_disposal_account_id: null,
  loss_on_disposal_account_id: null,
  asset_account: account(10, "1500", "Motor vehicles"),
  accumulated_depreciation_account: account(11, "1590", "Accum. dep."),
  depreciation_expense_account: account(12, "6000", "Depreciation"),
  gain_on_disposal_account: null,
  loss_on_disposal_account: null,
  assets_count: 4,
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
};

describe("parseFixedAssetCategory", () => {
  it("narrows a complete category row", () => {
    const parsed = parseFixedAssetCategory(validCategory);

    expect(parsed).not.toBeNull();
    expect(parsed?.code).toBe("MOTOR");
    expect(parsed?.asset_account?.code).toBe("1500");
    expect(parsed?.assets_count).toBe(4);
  });

  it("rejects a depreciation method the backend does not implement", () => {
    expect(
      parseFixedAssetCategory({ ...validCategory, depreciation_method: "UNITS_OF_PRODUCTION" }),
    ).toBeNull();
  });

  it("rejects a row missing a required account id", () => {
    expect(parseFixedAssetCategory({ ...validCategory, asset_account_id: undefined })).toBeNull();
  });
});

describe("parseFixedAssetCategoryOrThrow", () => {
  it("throws on an invalid payload", () => {
    expect(() => parseFixedAssetCategoryOrThrow({ id: 1 })).toThrow();
  });
});

describe("parseFixedAssetCategoryList", () => {
  it("parses an envelope-wrapped list", () => {
    const state = parseFixedAssetCategoryList({ success: true, data: [validCategory] }, 25);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });

  it("errors when no row survives parsing", () => {
    const state = parseFixedAssetCategoryList({ data: [{ id: 1 }] }, 25);

    expect(state.status).toBe("error");
  });
});

describe("category write validation", () => {
  const validPayload: FixedAssetCategoryCreatePayload = {
    code: "MOTOR",
    name: "Motor vehicles",
    description: null,
    useful_life_months: 60,
    depreciation_method: "STRAIGHT_LINE",
    asset_account_id: 10,
    accumulated_depreciation_account_id: 11,
    depreciation_expense_account_id: 12,
    gain_on_disposal_account_id: null,
    loss_on_disposal_account_id: null,
  };

  it("accepts a complete create payload", () => {
    expect(validateFixedAssetCategoryCreate(validPayload)).toEqual({});
  });

  it("flags the required create fields", () => {
    const errors = validateFixedAssetCategoryCreate({
      ...validPayload,
      code: "",
      name: "",
      useful_life_months: 0,
      asset_account_id: 0,
    });

    expect(errors.code).toBeDefined();
    expect(errors.name).toBeDefined();
    expect(errors.useful_life_months).toBeDefined();
    expect(errors.asset_account_id).toBeDefined();
  });

  it("only validates supplied update fields", () => {
    expect(validateFixedAssetCategoryUpdate({})).toEqual({});
    expect(validateFixedAssetCategoryUpdate({ name: "" })).toHaveProperty("name");
  });
});
