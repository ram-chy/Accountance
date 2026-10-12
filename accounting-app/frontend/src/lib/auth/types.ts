/**
 * Types for the authenticated session surfaced by the BFF to the browser.
 *
 * These mirror the verified Laravel resources exactly (`UserResource`,
 * `CompanyResource`, `CompanySettingResource`); no field is invented and none is
 * omitted that the UI legitimately needs. Permissions are not a top-level field
 * on the user — they hang off `roles[].permissions[]` (verified in
 * `auth/me`), so the UI flattens them with `collectPermissions`.
 */

export type SessionUserRolePermission = {
  id: number;
  name: string;
};

export type SessionUserRole = {
  id: number;
  name: string;
  permissions: SessionUserRolePermission[];
};

export type SessionUser = {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  email: string;
  mobile_no: string | null;
  email_verified: boolean;
  is_active: boolean;
  roles: SessionUserRole[];
  last_login_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type SessionCompanyAddress = {
  line_1: string | null;
  line_2: string | null;
  city: string | null;
  state: string | null;
  postal_code: string | null;
  country_code: string | null;
};

export type SessionCompanySettings = {
  id: number;
  company_id: number;
  invoice_number_prefix: string | null;
  quotation_number_prefix: string | null;
  default_payment_terms_days: number | null;
  default_currency_id: number | null;
  default_fiscal_year_start_month: number | null;
  created_at: string | null;
  updated_at: string | null;
};

export type SessionCompany = {
  id: number;
  name: string;
  legal_name: string | null;
  registration_number: string | null;
  tax_number: string | null;
  email: string | null;
  phone: string | null;
  website: string | null;
  address: SessionCompanyAddress;
  timezone: string;
  date_format: string;
  is_active: boolean;
  is_default: boolean;
  settings: SessionCompanySettings | null;
  created_at: string | null;
  updated_at: string | null;
};

/** The single payload the browser needs to render the authenticated shell. */
export type SessionSnapshot = {
  user: SessionUser;
  companies: SessionCompany[];
  activeCompanyId: number | null;
};
