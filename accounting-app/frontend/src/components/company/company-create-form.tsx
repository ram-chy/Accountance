"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { toast } from "sonner";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { createCompany } from "@/lib/company/api";
import { parseCompanyOrThrow, validateCompanyProfile } from "@/lib/company/validators";

import {
  CompanyFormFields,
  emptyProfileFormValues,
  profileFormValuesToPayload,
  type CompanyProfileFormValues,
} from "./company-form-fields";

/**
 * Create a company (`POST /companies`).
 *
 * The create request validates against the same rules as the update, so the
 * fields are shared. On success the creator is attached as a member by the
 * backend (`CompanyService::create`); when "work in this company" is left on,
 * the new company is made the default and the session is switched to it before
 * navigating to its settings. Otherwise the current active company is left
 * untouched.
 *
 * Creating a company requires `companies.create`, held by Admin alone; the
 * backend re-authorises the call.
 */
export function CompanyCreateForm() {
  const router = useRouter();
  const { switchCompany } = useAuth();
  const { can } = usePermissions();

  const [values, setValues] = useState<CompanyProfileFormValues>(emptyProfileFormValues);
  const [makeActive, setMakeActive] = useState(true);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  if (!can("companies.create")) {
    return (
      <EmptyState
        title="Not available"
        description="Creating a company requires the company create permission."
      />
    );
  }

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
      const company = parseCompanyOrThrow(
        await createCompany({ ...payload, is_default: makeActive }),
      );

      if (makeActive) {
        await switchCompany(company.id);
      }

      toast.success("Company created.");
      router.push("/settings/company");
      router.refresh();
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not create the company. Please try again.");
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {formError ? (
        <ErrorState title="Could not create company" message={formError} />
      ) : null}

      <CompanyFormFields
        idPrefix="new-company"
        values={values}
        errors={errors}
        disabled={saving}
        onChange={update}
      />

      <div className="flex flex-col gap-1.5">
        <label className="flex items-center gap-2 text-sm text-foreground">
          <input
            type="checkbox"
            checked={makeActive}
            onChange={(event) => setMakeActive(event.target.checked)}
            disabled={saving}
          />
          Work in this company after creating it
        </label>
        <p className="text-xs text-muted-foreground">
          Makes the new company your default and switches the active company.
        </p>
      </div>

      <div>
        <Button type="submit" loading={saving}>
          {saving ? "Creating…" : "Create company"}
        </Button>
      </div>
    </form>
  );
}
