import { describe, expect, it } from "vitest";

import type { FixedAsset, FixedAssetCreateCashPayload } from "./types";
import {
  parseDepreciationReport,
  parseDepreciationScheduleResponse,
  parseDisposeResponse,
  parseFixedAsset,
  parseFixedAssetList,
  validateDispose,
  validateFixedAssetCash,
  validateFixedAssetUpdate,
} from "./validators";

const account = (id: number, code: string, name: string) => ({
  id,
  code,
  name,
  account_type: "ASSET",
  is_active: true,
});

const validAsset: FixedAsset = {
  id: 7,
  asset_number: "FA-000007",
  name: "Delivery van",
  description: null,
  serial_number: null,
  supplier_reference: null,
  status: "DRAFT",
  acquisition_method: "CASH",
  fixed_asset_category_id: 3,
  category: {
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
    created_at: null,
    updated_at: null,
  },
  acquisition_date: "2026-01-01",
  depreciation_start_date: "2026-01-01",
  original_cost: "30000.0000",
  salvage_value: "3000.0000",
  useful_life_months: 60,
  depreciation_method: "STRAIGHT_LINE",
  accumulated_depreciation: "0.0000",
  carrying_value: "30000.0000",
  monthly_charge: "450.0000",
  period_count: 60,
  remaining_period_count: 60,
  asset_account_id: 10,
  accumulated_depreciation_account_id: 11,
  depreciation_expense_account_id: 12,
  gain_on_disposal_account_id: null,
  loss_on_disposal_account_id: null,
  acquisition_account_id: 1,
  asset_account: account(10, "1500", "Motor vehicles"),
  accumulated_depreciation_account: account(11, "1590", "Accum. dep."),
  depreciation_expense_account: account(12, "6000", "Depreciation"),
  acquisition_account: account(1, "1000", "Cash"),
  journal_id: null,
  journal: null,
  capitalised_at: null,
  fully_depreciated_at: null,
  disposed_at: null,
  depreciations: null,
  disposal: null,
  created_by: 1,
  updated_by: null,
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
};

describe("parseFixedAsset", () => {
  it("narrows a complete asset row", () => {
    const parsed = parseFixedAsset(validAsset);

    expect(parsed).not.toBeNull();
    expect(parsed?.asset_number).toBe("FA-000007");
    expect(parsed?.category?.code).toBe("MOTOR");
    expect(parsed?.acquisition_account?.code).toBe("1000");
  });

  it("rejects an unknown status rather than fabricating one", () => {
    expect(parseFixedAsset({ ...validAsset, status: "SOLD" })).toBeNull();
  });

  it("rejects a depreciation method the backend does not implement", () => {
    expect(parseFixedAsset({ ...validAsset, depreciation_method: "REDUCING_BALANCE" })).toBeNull();
  });

  it("rejects a row missing a required identifier", () => {
    expect(parseFixedAsset({ ...validAsset, id: undefined })).toBeNull();
  });

  it("drops malformed depreciation rows but keeps the asset", () => {
    const parsed = parseFixedAsset({
      ...validAsset,
      depreciations: [{ id: 1 }, null],
    });

    expect(parsed?.depreciations).toEqual([]);
  });
});

describe("parseFixedAssetList", () => {
  it("parses an envelope-wrapped list", () => {
    const state = parseFixedAssetList({ success: true, data: [validAsset] }, 25);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });

  it("parses a flat list", () => {
    const state = parseFixedAssetList([validAsset], 25);

    expect(state.status).toBe("ready");
    expect(state.items).toHaveLength(1);
  });

  it("errors when no row survives parsing", () => {
    const state = parseFixedAssetList({ data: [{ id: 1 }, { id: 2 }] }, 25);

    expect(state.status).toBe("error");
    expect(state.items).toHaveLength(0);
  });

  it("errors on a non-list payload", () => {
    const state = parseFixedAssetList(42, 25);

    expect(state.status).toBe("error");
  });
});

describe("parseDepreciationScheduleResponse", () => {
  it("reads the upcoming periods from the envelope", () => {
    const parsed = parseDepreciationScheduleResponse({
      data: {
        fixed_asset: validAsset,
        upcoming_periods: [
          {
            period_number: 1,
            period_start_date: "2026-01-01",
            period_end_date: "2026-01-31",
            posting_date: "2026-01-31",
            amount: "450.0000",
            is_final_period: false,
          },
        ],
      },
    });

    expect(parsed?.upcoming_periods).toHaveLength(1);
    expect(parsed?.upcoming_periods[0].period_number).toBe(1);
  });

  it("returns null when the embedded asset is invalid", () => {
    expect(
      parseDepreciationScheduleResponse({
        data: { fixed_asset: { id: 1 }, upcoming_periods: [] },
      }),
    ).toBeNull();
  });
});

describe("parseDisposeResponse", () => {
  it("reads the disposal and the refreshed asset", () => {
    const parsed = parseDisposeResponse({
      data: {
        disposal: {
          id: 1,
          fixed_asset_id: 7,
          disposal_date: "2026-06-01",
          reason: "Sold",
          proceeds: "12000.0000",
          carrying_value_at_disposal: "25000.0000",
          gain: "0.0000",
          loss: "13000.0000",
          net_result: "-13000.0000",
          proceeds_account_id: 1,
          proceeds_account: account(1, "1000", "Cash"),
          journal_id: 5,
          journal: null,
          posted_at: "2026-06-01T10:00:00Z",
          disposed_by: 1,
          created_at: null,
        },
        fixed_asset: { ...validAsset, status: "DISPOSED" },
      },
    });

    expect(parsed?.disposal.proceeds).toBe("12000.0000");
    expect(parsed?.fixed_asset.status).toBe("DISPOSED");
  });
});

describe("parseDepreciationReport", () => {
  it("parses the report totals and groups", () => {
    const parsed = parseDepreciationReport({
      data: {
        from: "2026-01-01",
        to: "2026-01-31",
        total_depreciation: "450.0000",
        assets: [
          {
            fixed_asset_id: 7,
            asset_number: "FA-000007",
            name: "Delivery van",
            category: "Motor vehicles",
            period_count: 1,
            total_depreciation: "450.0000",
            entries: [],
          },
        ],
      },
    });

    expect(parsed?.total_depreciation).toBe("450.0000");
    expect(parsed?.assets[0].period_count).toBe(1);
  });

  it("returns null when the range is missing", () => {
    expect(parseDepreciationReport({ data: { total_depreciation: "0" } })).toBeNull();
  });
});

describe("write validation", () => {
  const validPayload: FixedAssetCreateCashPayload = {
    fixed_asset_category_id: 3,
    name: "Delivery van",
    description: null,
    serial_number: null,
    supplier_reference: null,
    acquisition_date: "2026-01-01",
    depreciation_start_date: "2026-01-01",
    original_cost: "30000.0000",
    salvage_value: null,
    acquisition_account_id: 1,
  };

  it("accepts a complete create payload", () => {
    expect(validateFixedAssetCash(validPayload)).toEqual({});
  });

  it("flags the required create fields", () => {
    const errors = validateFixedAssetCash({
      ...validPayload,
      name: "",
      fixed_asset_category_id: 0,
      acquisition_account_id: 0,
      original_cost: "0",
    });

    expect(errors.name).toBeDefined();
    expect(errors.fixed_asset_category_id).toBeDefined();
    expect(errors.acquisition_account_id).toBeDefined();
    expect(errors.original_cost).toBeDefined();
  });

  it("flags an invalid date format", () => {
    expect(validateFixedAssetCash({ ...validPayload, acquisition_date: "01/01/2026" })).toHaveProperty(
      "acquisition_date",
    );
  });

  it("only validates supplied update fields", () => {
    expect(validateFixedAssetUpdate({})).toEqual({});
    expect(validateFixedAssetUpdate({ name: "  " })).toHaveProperty("name");
  });

  it("flags a negative disposal proceeds value", () => {
    const errors = validateDispose({
      disposal_date: "2026-06-01",
      proceeds: "-5",
      reason: null,
      proceeds_account_id: 1,
    });

    expect(errors.proceeds).toBeDefined();
  });

  it("requires a proceeds account on disposal", () => {
    const errors = validateDispose({
      disposal_date: "2026-06-01",
      proceeds: null,
      reason: null,
      proceeds_account_id: 0,
    });

    expect(errors.proceeds_account_id).toBeDefined();
  });
});
