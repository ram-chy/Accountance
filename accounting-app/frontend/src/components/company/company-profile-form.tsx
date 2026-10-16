"use client";

import { useState, type FormEvent } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { updateCompany } from "@/lib/company/api";
import type { Company } from "@/lib/company/types";
import { parseCompanyOrThrow, validateCompanyProfile } from "@/lib/company/validators";

import {
  CompanyFormFields,
  profileFormValuesFromCompany,
  profileFormValuesToPayload,
  type CompanyProfileFormValues,
} from "./company-form-fields";

/**
 * Company profile editor (name, legal/tax identity, address, locale defaults).
 *
 * Backed by `PUT /companies/{id}`. `country_code`, `timezone` and `date_format`
 * are required by the backend on every update, so they are always sent; every
 * other field is optional and an emptied field is sent as `null` to clear it,
 * matching the `nullable` rules. `id`, ownership and lifecycle metadata are
 * never sent — the backend owns them.
 *
 * The field set is shared with the create form via `CompanyFormFields`.
 */
export function CompanyProfileForm({
  company,
  onSaved,
}: {
  company: Company;
  onSaved: () => void;
}) {
  const [values, setValues] = useState<CompanyProfileFormValues>(() =>
    profileFormValuesFromCompany(company),
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const update = <K extends keyof CompanyProfileFormValues>(
    key: K,
    value: CompanyProfileFormValues[K],
  ) => setValues((current) => ({ ...current, [key]: value }));

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (saving) return;

    const payload = profileFormValuesToPayload(values);

    const clientErrors = validateCompanyProfile(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSaving(true);
    setErrors({});
    setFormError(null);

    try {
      parseCompanyOrThrow(await updateCompany(company.id, payload));
      toast.success("Company profile saved.");
      onSaved();
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save the company profile. Please try again.");
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {formError ? (
        <ErrorState title="Could not save company profile" message={formError} />
      ) : null}

      <CompanyFormFields
        idPrefix="company"
        values={values}
        errors={errors}
        disabled={saving}
        onChange={update}
      />

      <div>
        <Button type="submit" loading={saving}>
          {saving ? "Saving…" : "Save profile"}
        </Button>
      </div>
    </form>
  );
}
