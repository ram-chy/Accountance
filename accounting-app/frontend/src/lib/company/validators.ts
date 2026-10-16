/**
 * Runtime validation for company responses and inputs.
 *
 * API JSON is untrusted. `parseCompanySettings` narrows the
 * `CompanySettingResource` payload to the fields the settings form renders,
 * treating a non-numeric id as invalid rather than guessing. `parseCompany`
 * narrows a `CompanyResource` (returned by create/update) so a malformed
 * response never reaches the UI as a half-typed object.
 *
 * The input validators mirror the verified server rules
 * (`ValidatesCompany` trait, `UpdateCompanySettingsRequest`) so the form can
 * show the same messages before a round trip. The backend re-validates
 * everything and its message always wins.
 */

import type { Company, CompanySettings } from "./types";
import { COUNTRY_CODES, DATE_FORMATS } from "./types";

export class CompanyDataError extends Error {
  constructor(message = "The server returned an unexpected response.") {
    super(message);
    this.name = "CompanyDataError";
  }
}

/** Server limit for the numbering prefixes (`max:20`). */
export const MAX_PREFIX_LENGTH = 20;
export const PREFIX_PATTERN = /^[A-Za-z0-9\-\/]*$/;
export const MAX_PAYMENT_TERMS_DAYS = 365;
export const MIN_PAYMENT_TERMS_DAYS = 0;
export const MIN_FISCAL_MONTH = 1;
export const MAX_FISCAL_MONTH = 12;

const COUNTRY_CODE_SET = new Set<string>(COUNTRY_CODES);
const DATE_FORMAT_SET = new Set<string>(DATE_FORMATS);

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
// Mirrors the `phone` rule: optional leading +, then digits and separators.
const PHONE_PATTERN = /^\+?[0-9()\s.-]{7,30}$/;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function asStringOrNull(value: unknown): string | null {
  return typeof value === "string" ? value : null;
}

function asNumberOrNull(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

export function parseCompanySettings(raw: unknown): CompanySettings | null {
  if (!isRecord(raw)) return null;

  const id = asNumberOrNull(raw.id);
  const companyId = asNumberOrNull(raw.company_id);

  if (id === null || companyId === null) return null;

  return {
    id,
    company_id: companyId,
    invoice_number_prefix: asStringOrNull(raw.invoice_number_prefix),
    quotation_number_prefix: asStringOrNull(raw.quotation_number_prefix),
    default_payment_terms_days: asNumberOrNull(raw.default_payment_terms_days),
    default_currency_id: asNumberOrNull(raw.default_currency_id),
    default_fiscal_year_start_month: asNumberOrNull(
      raw.default_fiscal_year_start_month,
    ),
    created_at: asStringOrNull(raw.created_at),
    updated_at: asStringOrNull(raw.updated_at),
  };
}

export function parseCompanySettingsOrThrow(
  raw: unknown,
  label = "company settings",
): CompanySettings {
  const settings = parseCompanySettings(raw);
  if (settings === null) {
    throw new CompanyDataError(`The ${label} response was invalid.`);
  }
  return settings;
}

export function parseCompany(raw: unknown): Company | null {
  if (!isRecord(raw)) return null;

  const id = asNumberOrNull(raw.id);
  const name = typeof raw.name === "string" ? raw.name : null;

  if (id === null || name === null) return null;

  const address = isRecord(raw.address) ? raw.address : {};

  return {
    id,
    name,
    legal_name: asStringOrNull(raw.legal_name),
    registration_number: asStringOrNull(raw.registration_number),
    tax_number: asStringOrNull(raw.tax_number),
    email: asStringOrNull(raw.email),
    phone: asStringOrNull(raw.phone),
    website: asStringOrNull(raw.website),
    address: {
      line_1: asStringOrNull(address.line_1),
      line_2: asStringOrNull(address.line_2),
      city: asStringOrNull(address.city),
      state: asStringOrNull(address.state),
      postal_code: asStringOrNull(address.postal_code),
      country_code: asStringOrNull(address.country_code),
    },
    timezone: typeof raw.timezone === "string" ? raw.timezone : "UTC",
    date_format:
      typeof raw.date_format === "string" ? raw.date_format : "Y-m-d",
    is_active: raw.is_active === true,
    is_default: raw.is_default === true,
    settings: parseCompanySettings(raw.settings),
    created_at: asStringOrNull(raw.created_at),
    updated_at: asStringOrNull(raw.updated_at),
  };
}

export function parseCompanyOrThrow(raw: unknown, label = "company"): Company {
  const company = parseCompany(raw);
  if (company === null) {
    throw new CompanyDataError(`The ${label} response was invalid.`);
  }
  return company;
}

export type CompanyValidationErrors = Record<string, string>;

function hasValue(value: string | null): value is string {
  return value !== null && value.trim() !== "";
}

/** Validate a create/update company-profile payload against the server rules. */
export function validateCompanyProfile(
  payload: {
    name: string;
    email: string | null;
    phone: string | null;
    website: string | null;
    legal_name: string | null;
    registration_number: string | null;
    tax_number: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country_code: string;
    timezone: string;
    date_format: string;
  },
): CompanyValidationErrors {
  const errors: CompanyValidationErrors = {};

  if (payload.name.trim() === "") {
    errors.name = "Name is required.";
  } else if (payload.name.trim().length > 150) {
    errors.name = "Name must be 150 characters or fewer.";
  }

  if (hasValue(payload.email) && !EMAIL_PATTERN.test(payload.email.trim())) {
    errors.email = "Enter a valid email address.";
  }
  if (hasValue(payload.email) && payload.email.trim().length > 255) {
    errors.email = "Email must be 255 characters or fewer.";
  }

  if (hasValue(payload.phone) && !PHONE_PATTERN.test(payload.phone.trim())) {
    errors.phone = "Enter a valid phone number (7–30 digits and separators).";
  }

  if (hasValue(payload.website)) {
    let valid = true;
    try {
      const url = new URL(payload.website.trim());
      valid = url.protocol === "http:" || url.protocol === "https:";
    } catch {
      valid = false;
    }
    if (!valid) {
      errors.website = "Enter a valid http(s) URL.";
    }
    if (valid && payload.website.trim().length > 255) {
      errors.website = "Website must be 255 characters or fewer.";
    }
  }

  if (hasValue(payload.legal_name) && payload.legal_name.trim().length > 200) {
    errors.legal_name = "Legal name must be 200 characters or fewer.";
  }
  if (
    hasValue(payload.registration_number) &&
    payload.registration_number.trim().length > 100
  ) {
    errors.registration_number =
      "Registration number must be 100 characters or fewer.";
  }
  if (hasValue(payload.tax_number) && payload.tax_number.trim().length > 100) {
    errors.tax_number = "Tax number must be 100 characters or fewer.";
  }
  if (hasValue(payload.address_line_1) && payload.address_line_1.trim().length > 200) {
    errors.address_line_1 = "Address line must be 200 characters or fewer.";
  }
  if (hasValue(payload.address_line_2) && payload.address_line_2.trim().length > 200) {
    errors.address_line_2 = "Address line must be 200 characters or fewer.";
  }
  if (hasValue(payload.city) && payload.city.trim().length > 100) {
    errors.city = "City must be 100 characters or fewer.";
  }
  if (hasValue(payload.state) && payload.state.trim().length > 100) {
    errors.state = "State must be 100 characters or fewer.";
  }
  if (hasValue(payload.postal_code) && payload.postal_code.trim().length > 20) {
    errors.postal_code = "Postal code must be 20 characters or fewer.";
  }

  if (!COUNTRY_CODE_SET.has(payload.country_code)) {
    errors.country_code = "Select a two-letter country code.";
  }

  if (payload.timezone.trim() === "") {
    errors.timezone = "Timezone is required.";
  }

  if (!DATE_FORMAT_SET.has(payload.date_format)) {
    errors.date_format = "Select a date format.";
  }

  return errors;
}

/** Validate a settings payload against `UpdateCompanySettingsRequest`. */
export function validateCompanySettings(payload: {
  invoice_number_prefix?: string | null;
  quotation_number_prefix?: string | null;
  default_payment_terms_days?: number;
  default_currency_id?: number | null;
  default_fiscal_year_start_month?: number;
}): CompanyValidationErrors {
  const errors: CompanyValidationErrors = {};

  for (const key of ["invoice_number_prefix", "quotation_number_prefix"] as const) {
    const value = payload[key];
    if (typeof value === "string") {
      const trimmed = value.trim();
      if (trimmed.length > MAX_PREFIX_LENGTH) {
        errors[key] = `Prefix must be ${MAX_PREFIX_LENGTH} characters or fewer.`;
      } else if (!PREFIX_PATTERN.test(trimmed)) {
        errors[key] = "Use only letters, numbers, hyphens and slashes.";
      }
    }
  }

  const days = payload.default_payment_terms_days;
  if (days !== undefined) {
    if (!Number.isInteger(days) || days < MIN_PAYMENT_TERMS_DAYS || days > MAX_PAYMENT_TERMS_DAYS) {
      errors.default_payment_terms_days =
        `Payment terms must be a whole number between ${MIN_PAYMENT_TERMS_DAYS} and ${MAX_PAYMENT_TERMS_DAYS}.`;
    }
  }

  const month = payload.default_fiscal_year_start_month;
  if (month !== undefined) {
    if (!Number.isInteger(month) || month < MIN_FISCAL_MONTH || month > MAX_FISCAL_MONTH) {
      errors.default_fiscal_year_start_month =
        `Fiscal year start month must be between ${MIN_FISCAL_MONTH} and ${MAX_FISCAL_MONTH}.`;
    }
  }

  const currencyId = payload.default_currency_id;
  if (currencyId !== undefined && currencyId !== null) {
    if (!Number.isInteger(currencyId) || currencyId < 1) {
      errors.default_currency_id = "Select a currency.";
    }
  }

  return errors;
}
