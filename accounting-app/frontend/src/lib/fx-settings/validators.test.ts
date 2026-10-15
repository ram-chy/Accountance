import { describe, expect, it } from "vitest";

import {
  FxSettingsDataError,
  parseCompanyFxSetting,
  parseCompanyFxSettingOrThrow,
  validateFxSettingUpdate,
} from "./validators";

const configured = {
  company_id: 1,
  realized_gain_account_id: 30,
  realized_loss_account_id: 40,
  is_configured: true,
  realized_gain_account: { id: 30, code: "4100", name: "FX gain" },
  realized_loss_account: { id: 40, code: "6100", name: "FX loss" },
  updated_at: "2026-04-01T00:00:00Z",
};

describe("parseCompanyFxSetting", () => {
  it("narrows a configured setting with both accounts", () => {
    const setting = parseCompanyFxSetting(configured);

    expect(setting?.is_configured).toBe(true);
    expect(setting?.realized_gain_account?.code).toBe("4100");
  });

  it("narrows an unconfigured setting", () => {
    const setting = parseCompanyFxSetting({
      ...configured,
      realized_gain_account_id: null,
      realized_loss_account_id: null,
      is_configured: false,
      realized_gain_account: null,
      realized_loss_account: null,
    });

    expect(setting?.is_configured).toBe(false);
    expect(setting?.realized_gain_account).toBeNull();
  });

  it("drops a response missing the derived configured flag", () => {
    expect(parseCompanyFxSetting({ ...configured, is_configured: undefined })).toBeNull();
  });

  it("throws an FxSettingsDataError for a malformed payload", () => {
    expect(() => parseCompanyFxSettingOrThrow({})).toThrow(FxSettingsDataError);
  });
});

describe("validateFxSettingUpdate", () => {
  it("accepts a both-set pair", () => {
    expect(
      validateFxSettingUpdate({ realized_gain_account_id: 30, realized_loss_account_id: 40 }),
    ).toEqual({});
  });

  it("accepts a both-cleared pair", () => {
    expect(
      validateFxSettingUpdate({ realized_gain_account_id: null, realized_loss_account_id: null }),
    ).toEqual({});
  });

  it("rejects a half-set pair", () => {
    const errors = validateFxSettingUpdate({
      realized_gain_account_id: 30,
      realized_loss_account_id: null,
    });

    expect(errors.realized_loss_account_id).toBeDefined();
  });
});
