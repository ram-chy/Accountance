/**
 * Company administration data contracts.
 *
 * These mirror the verified Laravel `CompanyResource`, `CompanySettingResource`,
 * `StoreCompanyRequest`/`UpdateCompanyRequest` (via the `ValidatesCompany`
 * trait) and `UpdateCompanySettingsRequest` — see `CompanyController.php`,
 * `CompanySettingsController.php` and `FRONTEND_API_HANDOFF.md` §11.
 *
 * `Company` and `CompanySettings` are the exact shapes already carried in the
 * session snapshot (`CompanyResource`), so they are re-exported rather than
 * restated; the profile editor and the session therefore cannot drift.
 *
 * There is no base/functional-currency value on `CompanyResource` — the backend
 * exposes only `settings.default_currency_id` (a nullable id). The frontend
 * must not invent a base-currency display; see the Phase 20 report.
 */

import type {
  SessionCompany,
  SessionCompanyAddress,
  SessionCompanySettings,
} from "@/lib/auth/types";

export type Company = SessionCompany;
export type CompanyAddress = SessionCompanyAddress;
export type CompanySettings = SessionCompanySettings;

/**
 * Presentation date formats offered to companies, from
 * `App\Config\DateFormats::ALL`. The order (and the default) match the backend.
 */
export const DATE_FORMATS = [
  "Y-m-d",
  "d/m/Y",
  "d-m-Y",
  "m/d/Y",
  "d.m.Y",
  "Y/m/d",
  "F j, Y",
  "j F Y",
] as const;

export type DateFormat = (typeof DATE_FORMATS)[number];

export const DEFAULT_DATE_FORMAT: DateFormat = "Y-m-d";

/**
 * ISO 3166-1 alpha-2 country codes, transcribed from `App\Config\CountryCodes`
 * (the authoritative list the `CountryCode` rule validates against). The
 * backend list is additive-only, so this copy can only ever lag by a newly
 * added territory — it never offers a code the backend rejects.
 */
export const COUNTRY_CODES = [
  "AD", "AE", "AF", "AG", "AI", "AL", "AM", "AO", "AQ", "AR",
  "AS", "AT", "AU", "AW", "AX", "AZ", "BA", "BB", "BD", "BE",
  "BF", "BG", "BH", "BI", "BJ", "BL", "BM", "BN", "BO", "BQ",
  "BR", "BS", "BT", "BV", "BW", "BY", "BZ", "CA", "CC", "CD",
  "CF", "CG", "CH", "CI", "CK", "CL", "CM", "CN", "CO", "CR",
  "CU", "CV", "CW", "CX", "CY", "CZ", "DE", "DJ", "DK", "DM",
  "DO", "DZ", "EC", "EE", "EG", "EH", "ER", "ES", "ET", "FI",
  "FJ", "FK", "FM", "FO", "FR", "GA", "GB", "GD", "GE", "GF",
  "GG", "GH", "GI", "GL", "GM", "GN", "GP", "GQ", "GR", "GS",
  "GT", "GU", "GW", "GY", "HK", "HM", "HN", "HR", "HT", "HU",
  "ID", "IE", "IL", "IM", "IN", "IO", "IQ", "IR", "IS", "IT",
  "JE", "JM", "JO", "JP", "KE", "KG", "KH", "KI", "KM", "KN",
  "KP", "KR", "KW", "KY", "KZ", "LA", "LB", "LC", "LI", "LK",
  "LR", "LS", "LT", "LU", "LV", "LY", "MA", "MC", "MD", "ME",
  "MF", "MG", "MH", "MK", "ML", "MM", "MN", "MO", "MP", "MQ",
  "MR", "MS", "MT", "MU", "MV", "MW", "MX", "MY", "MZ", "NA",
  "NC", "NE", "NF", "NG", "NI", "NL", "NO", "NP", "NR", "NU",
  "NZ", "OM", "PA", "PE", "PF", "PG", "PH", "PK", "PL", "PM",
  "PN", "PR", "PS", "PT", "PW", "PY", "QA", "RE", "RO", "RS",
  "RU", "RW", "SA", "SB", "SC", "SD", "SE", "SG", "SH", "SI",
  "SJ", "SK", "SL", "SM", "SN", "SO", "SR", "SS", "ST", "SV",
  "SX", "SY", "SZ", "TC", "TD", "TF", "TG", "TH", "TJ", "TK",
  "TL", "TM", "TN", "TO", "TR", "TT", "TV", "TW", "TZ", "UA",
  "UG", "UM", "US", "UY", "UZ", "VA", "VC", "VE", "VG", "VI",
  "VN", "VU", "WF", "WS", "YE", "YT", "ZA", "ZM", "ZW",
] as const;

export const DEFAULT_COUNTRY_CODE = "US";

/**
 * Editable company profile fields. `country_code`, `timezone` and `date_format`
 * are always sent (the backend marks them required on both create and update);
 * every other field is nullable and a null clears it.
 */
export type CompanyProfilePayload = {
  name: string;
  legal_name: string | null;
  registration_number: string | null;
  tax_number: string | null;
  email: string | null;
  phone: string | null;
  website: string | null;
  address_line_1: string | null;
  address_line_2: string | null;
  city: string | null;
  state: string | null;
  postal_code: string | null;
  country_code: string;
  timezone: string;
  date_format: string;
};

/** `POST /companies` body (validated by `StoreCompanyRequest`). */
export type CompanyCreatePayload = CompanyProfilePayload & {
  /** Defaults to true server-side; a new company is attached as the caller's. */
  is_default?: boolean;
};

/**
 * `PUT /company/settings` body (validated by `UpdateCompanySettingsRequest`).
 *
 * Every key is optional and "not mentioned means unchanged". Only the prefixes
 * and the default currency are nullable (a null clears them); payment terms and
 * the fiscal-year month are `sometimes` integers, so they are omitted rather
 * than sent as null when the user leaves them blank.
 */
export type CompanySettingsUpdatePayload = {
  invoice_number_prefix?: string | null;
  quotation_number_prefix?: string | null;
  default_payment_terms_days?: number;
  default_currency_id?: number | null;
  default_fiscal_year_start_month?: number;
};

export type CompanySettingsState = {
  status: "loading" | "ready" | "error" | "unavailable";
  settings: CompanySettings | null;
  message?: string;
  requestId?: string;
};
