"use client";

import { FormField, Input } from "@/components/ui/field";
import type { Company, CompanyProfilePayload } from "@/lib/company/types";
import {
  COUNTRY_CODES,
  DATE_FORMATS,
  DEFAULT_COUNTRY_CODE,
  DEFAULT_DATE_FORMAT,
} from "@/lib/company/types";

/**
 * Shared company-profile fields, used by both the create form
 * (`POST /companies`) and the edit form (`PUT /companies/{id}`).
 *
 * The two requests validate against the same rule set (the `ValidatesCompany`
 * trait), so the field set, hints and limits are defined once here and the
 * payload mapping lives in `profileFormValuesToPayload`. Keeping them together
 * means create and edit cannot drift.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export type CompanyProfileFormValues = {
  name: string;
  legalName: string;
  registrationNumber: string;
  taxNumber: string;
  email: string;
  phone: string;
  website: string;
  line1: string;
  line2: string;
  city: string;
  state: string;
  postalCode: string;
  countryCode: string;
  timezone: string;
  dateFormat: string;
};

function timeZoneSuggestions(): string[] {
  try {
    const supported = (
      Intl as typeof Intl & { supportedValuesOf?: (key: string) => string[] }
    ).supportedValuesOf;

    if (typeof supported === "function") {
      const zones = supported("timeZone");
      return zones.includes("UTC") ? zones : ["UTC", ...zones];
    }
  } catch {
    // Older runtimes omit `supportedValuesOf`; the field stays a plain input.
  }

  return ["UTC"];
}

const TIMEZONE_SUGGESTIONS = timeZoneSuggestions();

function resolveTimeZone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
  } catch {
    return "UTC";
  }
}

/** Starting values for a new company, seeded from the browser locale. */
export function emptyProfileFormValues(): CompanyProfileFormValues {
  return {
    name: "",
    legalName: "",
    registrationNumber: "",
    taxNumber: "",
    email: "",
    phone: "",
    website: "",
    line1: "",
    line2: "",
    city: "",
    state: "",
    postalCode: "",
    countryCode: DEFAULT_COUNTRY_CODE,
    timezone: resolveTimeZone(),
    dateFormat: DEFAULT_DATE_FORMAT,
  };
}

/** Values pre-filled from an existing company for editing. */
export function profileFormValuesFromCompany(company: Company): CompanyProfileFormValues {
  return {
    name: company.name,
    legalName: company.legal_name ?? "",
    registrationNumber: company.registration_number ?? "",
    taxNumber: company.tax_number ?? "",
    email: company.email ?? "",
    phone: company.phone ?? "",
    website: company.website ?? "",
    line1: company.address.line_1 ?? "",
    line2: company.address.line_2 ?? "",
    city: company.address.city ?? "",
    state: company.address.state ?? "",
    postalCode: company.address.postal_code ?? "",
    countryCode: company.address.country_code ?? DEFAULT_COUNTRY_CODE,
    timezone: company.timezone,
    dateFormat: company.date_format,
  };
}

function emptyToNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

/** Map form values onto the `CompanyProfilePayload` shared by create and update. */
export function profileFormValuesToPayload(
  values: CompanyProfileFormValues,
): CompanyProfilePayload {
  return {
    name: values.name.trim(),
    legal_name: emptyToNull(values.legalName),
    registration_number: emptyToNull(values.registrationNumber),
    tax_number: emptyToNull(values.taxNumber),
    email: emptyToNull(values.email),
    phone: emptyToNull(values.phone),
    website: emptyToNull(values.website),
    address_line_1: emptyToNull(values.line1),
    address_line_2: emptyToNull(values.line2),
    city: emptyToNull(values.city),
    state: emptyToNull(values.state),
    postal_code: emptyToNull(values.postalCode),
    country_code: values.countryCode,
    timezone: values.timezone.trim(),
    date_format: values.dateFormat,
  };
}

export function CompanyFormFields({
  idPrefix,
  values,
  errors,
  disabled,
  onChange,
}: {
  idPrefix: string;
  values: CompanyProfileFormValues;
  errors: Record<string, string>;
  disabled?: boolean;
  onChange: <K extends keyof CompanyProfileFormValues>(
    key: K,
    value: CompanyProfileFormValues[K],
  ) => void;
}) {
  const id = (suffix: string) => `${idPrefix}-${suffix}`;

  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label="Company name" htmlFor={id("name")} required error={errors.name}>
          <Input
            id={id("name")}
            value={values.name}
            onChange={(event) => onChange("name", event.target.value)}
            invalid={Boolean(errors.name)}
            required
            maxLength={150}
            disabled={disabled}
          />
        </FormField>

        <FormField label="Legal name" htmlFor={id("legal-name")} error={errors.legal_name}>
          <Input
            id={id("legal-name")}
            value={values.legalName}
            onChange={(event) => onChange("legalName", event.target.value)}
            invalid={Boolean(errors.legal_name)}
            maxLength={200}
            disabled={disabled}
          />
        </FormField>

        <FormField
          label="Registration number"
          htmlFor={id("registration")}
          error={errors.registration_number}
        >
          <Input
            id={id("registration")}
            value={values.registrationNumber}
            onChange={(event) => onChange("registrationNumber", event.target.value)}
            invalid={Boolean(errors.registration_number)}
            maxLength={100}
            disabled={disabled}
          />
        </FormField>

        <FormField label="Tax number" htmlFor={id("tax")} error={errors.tax_number}>
          <Input
            id={id("tax")}
            value={values.taxNumber}
            onChange={(event) => onChange("taxNumber", event.target.value)}
            invalid={Boolean(errors.tax_number)}
            maxLength={100}
            disabled={disabled}
          />
        </FormField>

        <FormField label="Email" htmlFor={id("email")} error={errors.email}>
          <Input
            id={id("email")}
            type="email"
            inputMode="email"
            value={values.email}
            onChange={(event) => onChange("email", event.target.value)}
            invalid={Boolean(errors.email)}
            maxLength={255}
            disabled={disabled}
          />
        </FormField>

        <FormField
          label="Phone"
          htmlFor={id("phone")}
          error={errors.phone}
          hint="Digits with optional separators, e.g. +1 555 0100."
        >
          <Input
            id={id("phone")}
            value={values.phone}
            onChange={(event) => onChange("phone", event.target.value)}
            invalid={Boolean(errors.phone)}
            maxLength={30}
            disabled={disabled}
          />
        </FormField>

        <FormField
          label="Website"
          htmlFor={id("website")}
          error={errors.website}
          hint="Must start with http:// or https://."
        >
          <Input
            id={id("website")}
            value={values.website}
            onChange={(event) => onChange("website", event.target.value)}
            invalid={Boolean(errors.website)}
            maxLength={255}
            disabled={disabled}
          />
        </FormField>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label="Address line 1" htmlFor={id("address1")} error={errors.address_line_1}>
          <Input
            id={id("address1")}
            value={values.line1}
            onChange={(event) => onChange("line1", event.target.value)}
            invalid={Boolean(errors.address_line_1)}
            maxLength={200}
            disabled={disabled}
          />
        </FormField>

        <FormField label="Address line 2" htmlFor={id("address2")} error={errors.address_line_2}>
          <Input
            id={id("address2")}
            value={values.line2}
            onChange={(event) => onChange("line2", event.target.value)}
            invalid={Boolean(errors.address_line_2)}
            maxLength={200}
            disabled={disabled}
          />
        </FormField>

        <FormField label="City" htmlFor={id("city")} error={errors.city}>
          <Input
            id={id("city")}
            value={values.city}
            onChange={(event) => onChange("city", event.target.value)}
            invalid={Boolean(errors.city)}
            maxLength={100}
            disabled={disabled}
          />
        </FormField>

        <FormField label="State / region" htmlFor={id("state")} error={errors.state}>
          <Input
            id={id("state")}
            value={values.state}
            onChange={(event) => onChange("state", event.target.value)}
            invalid={Boolean(errors.state)}
            maxLength={100}
            disabled={disabled}
          />
        </FormField>

        <FormField label="Postal code" htmlFor={id("postal")} error={errors.postal_code}>
          <Input
            id={id("postal")}
            value={values.postalCode}
            onChange={(event) => onChange("postalCode", event.target.value)}
            invalid={Boolean(errors.postal_code)}
            maxLength={20}
            disabled={disabled}
          />
        </FormField>

        <FormField label="Country" htmlFor={id("country")} required error={errors.country_code}>
          <select
            id={id("country")}
            className={selectClasses}
            value={values.countryCode}
            onChange={(event) => onChange("countryCode", event.target.value)}
            aria-invalid={Boolean(errors.country_code) || undefined}
            disabled={disabled}
          >
            {COUNTRY_CODES.map((code) => (
              <option key={code} value={code}>
                {code}
              </option>
            ))}
          </select>
        </FormField>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField
          label="Timezone"
          htmlFor={id("timezone")}
          required
          error={errors.timezone}
          hint="An IANA timezone identifier, e.g. Europe/London."
        >
          <Input
            id={id("timezone")}
            list={id("timezone-options")}
            value={values.timezone}
            onChange={(event) => onChange("timezone", event.target.value)}
            invalid={Boolean(errors.timezone)}
            required
            autoComplete="off"
            disabled={disabled}
          />
          <datalist id={id("timezone-options")}>
            {TIMEZONE_SUGGESTIONS.map((zone) => (
              <option key={zone} value={zone} />
            ))}
          </datalist>
        </FormField>

        <FormField
          label="Date format"
          htmlFor={id("date-format")}
          required
          error={errors.date_format}
          hint="How dates are displayed across documents and reports."
        >
          <select
            id={id("date-format")}
            className={selectClasses}
            value={values.dateFormat}
            onChange={(event) => onChange("dateFormat", event.target.value)}
            aria-invalid={Boolean(errors.date_format) || undefined}
            disabled={disabled}
          >
            {DATE_FORMATS.map((format) => (
              <option key={format} value={format}>
                {format}
              </option>
            ))}
          </select>
        </FormField>
      </div>
    </>
  );
}
