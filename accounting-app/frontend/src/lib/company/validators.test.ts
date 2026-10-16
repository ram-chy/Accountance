import { describe, expect, it } from "vitest";

import {
  CompanyDataError,
  parseCompany,
  parseCompanyOrThrow,
  parseCompanySettings,
  parseCompanySettingsOrThrow,
  validateCompanyProfile,
  validateCompanySettings,
} from "./validators";

const validSettings = {
  id: 1,
  company_id: 5,
  invoice_number_prefix: "INV",
  quotation_number_prefix: null,
  default_payment_terms_days: 30,
  default_currency_id: 2,
  default_fiscal_year_start_month: 1,
  created_at: "2026-04-01T00:00:00Z",
  updated_at: null,
};

const validCompany = {
  id: 5,
  name: "Acme Ltd",
  legal_name: null,
  registration_number: null,
  tax_number: null,
  email: "hello@acme.test",
  phone: null,
  website: null,
  address: {
    line_1: "1 Main St",
    line_2: null,
    city: "Springfield",
    state: null,
    postal_code: null,
    country_code: "US",
  },
  timezone: "UTC",
  date_format: "Y-m-d",
  is_active: true,
  is_default: true,
  settings: validSettings,
  created_at: null,
  updated_at: null,
};

function profile(overrides: Record<string, unknown> = {}) {
  return {
    name: "Acme Ltd",
    legal_name: null,
    registration_number: null,
    tax_number: null,
    email: null,
    phone: null,
    website: null,
    address_line_1: null,
    address_line_2: null,
    city: null,
    state: null,
    postal_code: null,
    country_code: "US",
    timezone: "UTC",
    date_format: "Y-m-d",
    ...overrides,
  } as Parameters<typeof validateCompanyProfile>[0];
}

describe("parseCompanySettings", () => {
  it("narrows a complete settings row", () => {
    const settings = parseCompanySettings(validSettings);

    expect(settings).not.toBeNull();
    expect(settings?.invoice_number_prefix).toBe("INV");
    expect(settings?.default_payment_terms_days).toBe(30);
  });

  it("tolerates null optional values", () => {
    const settings = parseCompanySettings({
      ...validSettings,
      invoice_number_prefix: null,
      default_currency_id: null,
      default_payment_terms_days: null,
    });

    expect(settings?.invoice_number_prefix).toBeNull();
    expect(settings?.default_currency_id).toBeNull();
  });

  it("drops a row with a non-numeric id", () => {
    expect(parseCompanySettings({ ...validSettings, id: "1" })).toBeNull();
  });

  it("throws through the OrThrow variant", () => {
    expect(() => parseCompanySettingsOrThrow({})).toThrow(CompanyDataError);
  });
});

describe("parseCompany", () => {
  it("narrows a company resource including its address", () => {
    const company = parseCompany(validCompany);

    expect(company?.id).toBe(5);
    expect(company?.address.city).toBe("Springfield");
    expect(company?.is_active).toBe(true);
  });

  it("falls back to the default timezone and date format", () => {
    const company = parseCompany({ ...validCompany, timezone: undefined, date_format: undefined });

    expect(company?.timezone).toBe("UTC");
    expect(company?.date_format).toBe("Y-m-d");
  });

  it("tolerates a missing address object", () => {
    const company = parseCompany({ ...validCompany, address: undefined });

    expect(company?.address.line_1).toBeNull();
  });

  it("drops a company missing its name", () => {
    expect(parseCompany({ ...validCompany, name: undefined })).toBeNull();
  });

  it("throws through the OrThrow variant", () => {
    expect(() => parseCompanyOrThrow(null)).toThrow(CompanyDataError);
  });
});

describe("validateCompanyProfile", () => {
  it("accepts a minimal valid payload", () => {
    expect(validateCompanyProfile(profile())).toEqual({});
  });

  it("requires a name", () => {
    expect(validateCompanyProfile(profile({ name: "  " })).name).toBeDefined();
  });

  it("rejects an unknown country code", () => {
    expect(validateCompanyProfile(profile({ country_code: "ZZ" })).country_code).toBeDefined();
  });

  it("rejects an unknown date format", () => {
    expect(validateCompanyProfile(profile({ date_format: "nope" })).date_format).toBeDefined();
  });

  it("rejects a non-http website", () => {
    expect(validateCompanyProfile(profile({ website: "ftp://acme.test" })).website).toBeDefined();
  });

  it("accepts an https website", () => {
    expect(validateCompanyProfile(profile({ website: "https://acme.test" })).website).toBeUndefined();
  });
});

describe("validateCompanySettings", () => {
  it("accepts an empty payload", () => {
    expect(validateCompanySettings({})).toEqual({});
  });

  it("rejects an over-long prefix", () => {
    expect(
      validateCompanySettings({ invoice_number_prefix: "X".repeat(21) }).invoice_number_prefix,
    ).toBeDefined();
  });

  it("rejects prefix characters outside the allowed set", () => {
    expect(
      validateCompanySettings({ quotation_number_prefix: "INV!" }).quotation_number_prefix,
    ).toBeDefined();
  });

  it("rejects payment terms outside 0–365", () => {
    expect(
      validateCompanySettings({ default_payment_terms_days: 400 }).default_payment_terms_days,
    ).toBeDefined();
  });

  it("rejects a fiscal month outside 1–12", () => {
    expect(
      validateCompanySettings({ default_fiscal_year_start_month: 13 })
        .default_fiscal_year_start_month,
    ).toBeDefined();
  });

  it("rejects a non-positive currency id", () => {
    expect(validateCompanySettings({ default_currency_id: 0 }).default_currency_id).toBeDefined();
  });

  it("allows a null currency id", () => {
    expect(validateCompanySettings({ default_currency_id: null })).toEqual({});
  });
});
