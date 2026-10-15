"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { FormField, Input } from "@/components/ui/field";
import { useAccountOptions } from "@/lib/accounts/use-account-options";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { useFixedAssetCategoryOptions } from "@/lib/fixed-asset-categories/use-fixed-asset-category-options";
import { createFixedAssetCash, createFixedAssetSupplierCredit, updateFixedAsset } from "@/lib/fixed-assets/api";
import type {
  FixedAsset,
  FixedAssetAcquisitionMethod,
  FixedAssetCreateCashPayload,
  FixedAssetUpdatePayload,
} from "@/lib/fixed-assets/types";
import {
  parseFixedAssetOrThrow,
  validateFixedAssetCash,
  validateFixedAssetUpdate,
} from "@/lib/fixed-assets/validators";

/**
 * Create or edit a fixed asset.
 *
 * Creation carries the acquisition method explicitly (the verified request has
 * no `acquisition_method` field; it is chosen by the endpoint `/cash` versus
 * `/supplier-credit`). The category supplies the useful life, depreciation
 * method, and the depreciation accounts, so they are not entered per asset. Money
 * is entered as a decimal string and forwarded verbatim so no floating-point
 * rounding ever reaches the ledger. Editing is offered for drafts only, matching
 * the backend rule that a capitalised asset is immutable.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

const textareaClasses =
  "min-h-20 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type AssetForm = {
  name: string;
  description: string;
  serial_number: string;
  supplier_reference: string;
  fixed_asset_category_id: string;
  acquisition_date: string;
  depreciation_start_date: string;
  original_cost: string;
  salvage_value: string;
  acquisition_account_id: string;
};

function orNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function toId(value: string): number {
  const parsed = Number.parseInt(value, 10);
  return Number.isInteger(parsed) ? parsed : 0;
}

function today(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");
  return `${now.getFullYear()}-${month}-${day}`;
}

function emptyForm(): AssetForm {
  const date = today();
  return {
    name: "",
    description: "",
    serial_number: "",
    supplier_reference: "",
    fixed_asset_category_id: "",
    acquisition_date: date,
    depreciation_start_date: date,
    original_cost: "",
    salvage_value: "0",
    acquisition_account_id: "",
  };
}

function toForm(asset: FixedAsset): AssetForm {
  return {
    name: asset.name,
    description: asset.description ?? "",
    serial_number: asset.serial_number ?? "",
    supplier_reference: asset.supplier_reference ?? "",
    fixed_asset_category_id: String(asset.fixed_asset_category_id),
    acquisition_date: asset.acquisition_date,
    depreciation_start_date: asset.depreciation_start_date,
    original_cost: asset.original_cost,
    salvage_value: asset.salvage_value,
    acquisition_account_id: String(asset.acquisition_account_id),
  };
}

export function FixedAssetForm({ asset }: { asset?: FixedAsset }) {
  const router = useRouter();
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const isEdit = Boolean(asset);
  const canCreate = can("accounting.fixed_asset.create");
  const canUpdate = can("accounting.fixed_asset.update");
  const allowed = isEdit ? canUpdate : canCreate;

  const [acquisitionMethod, setAcquisitionMethod] = useState<FixedAssetAcquisitionMethod>(
    asset?.acquisition_method ?? "CASH",
  );
  const [form, setForm] = useState<AssetForm>(() => (asset ? toForm(asset) : emptyForm()));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const categoriesState = useFixedAssetCategoryOptions(companyId, allowed);
  const categories = categoriesState.status === "ready" ? categoriesState.items : [];
  const activeCategories = categories.filter((category) => category.is_active || isEdit);

  const accountsState = useAccountOptions(companyId, allowed);
  const accounts = accountsState.status === "ready" ? accountsState.items : [];

  const set = <K extends keyof AssetForm>(key: K, value: AssetForm[K]) => {
    setForm((current) => {
      const next = { ...current, [key]: value };
      // Keep the depreciation start date in step with the acquisition date until
      // the user deliberately sets it apart.
      if (key === "acquisition_date" && current.depreciation_start_date === current.acquisition_date) {
        next.depreciation_start_date = value;
      }
      return next;
    });
    setErrors((current) => {
      if (!(key in current)) return current;
      const next = { ...current };
      delete next[key as string];
      return next;
    });
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;

    const base = {
      fixed_asset_category_id: toId(form.fixed_asset_category_id),
      name: form.name.trim(),
      description: orNull(form.description),
      serial_number: orNull(form.serial_number),
      supplier_reference: orNull(form.supplier_reference),
      acquisition_date: form.acquisition_date,
      depreciation_start_date: form.depreciation_start_date,
      original_cost: form.original_cost.trim(),
      salvage_value: form.salvage_value.trim() === "" ? null : form.salvage_value.trim(),
      acquisition_account_id: toId(form.acquisition_account_id),
    };

    const payload: FixedAssetCreateCashPayload = base;

    const clientErrors = isEdit
      ? validateFixedAssetUpdate(base as FixedAssetUpdatePayload)
      : validateFixedAssetCash(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const raw =
        isEdit && asset
          ? await updateFixedAsset(asset.id, base as FixedAssetUpdatePayload)
          : acquisitionMethod === "CASH"
            ? await createFixedAssetCash(payload)
            : await createFixedAssetSupplierCredit(payload);
      const saved = parseFixedAssetOrThrow(raw);
      router.push(`/fixed-assets/${saved.id}`);
      router.refresh();
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this asset. Please try again.");
      }
      setSubmitting(false);
    }
  };

  if (!activeCompany) {
    return <EmptyState title="No company selected" description="Choose a company to add fixed assets." />;
  }

  if (!allowed) {
    return (
      <EmptyState
        title={isEdit ? "Editing unavailable" : "Creating unavailable"}
        description={
          isEdit
            ? "Your role does not include permission to edit fixed assets."
            : "Your role does not include permission to create fixed assets."
        }
      />
    );
  }

  const acquisitionAccountLabel =
    acquisitionMethod === "CASH" ? "Cash / bank account" : "Payable account";

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-2">
        <Link href="/fixed-assets" className="text-sm font-medium text-primary hover:underline">
          ← Fixed assets
        </Link>
        <h1 className="text-2xl font-semibold text-foreground">
          {isEdit ? `Edit ${asset?.asset_number}` : "New fixed asset"}
        </h1>
        <p className="text-sm text-muted-foreground">
          {isEdit
            ? "Update the draft asset. Saving keeps it a draft until it is capitalised."
            : "Record an acquisition. Saving creates a draft that must be capitalised before depreciation starts."}
        </p>
      </div>

      <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
        {formError ? (
          <p
            role="alert"
            className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
          >
            {formError}
          </p>
        ) : null}

        <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
          <h2 className="text-base font-semibold text-foreground">Acquisition</h2>

          <div className="grid gap-4 sm:grid-cols-2">
            {!isEdit ? (
              <FormField
                label="Acquisition method"
                htmlFor="fa-method"
                required
                hint="Cash purchases credit a cash/bank account; supplier credit credits a payable."
              >
                <select
                  id="fa-method"
                  className={selectClasses}
                  value={acquisitionMethod}
                  onChange={(event) => setAcquisitionMethod(event.target.value as FixedAssetAcquisitionMethod)}
                >
                  <option value="CASH">Cash</option>
                  <option value="SUPPLIER_CREDIT">Supplier credit</option>
                </select>
              </FormField>
            ) : (
              <FormField label="Acquisition method" htmlFor="fa-method-readonly">
                <Input
                  id="fa-method-readonly"
                  value={asset?.acquisition_method === "CASH" ? "Cash" : "Supplier credit"}
                  readOnly
                  disabled
                />
              </FormField>
            )}

            <FormField
              label="Category"
              htmlFor="fa-category"
              required
              error={errors.fixed_asset_category_id}
              hint="Supplies the useful life, depreciation method, and depreciation accounts."
            >
              <select
                id="fa-category"
                className={selectClasses}
                value={form.fixed_asset_category_id}
                onChange={(event) => set("fixed_asset_category_id", event.target.value)}
                disabled={categoriesState.status !== "ready"}
                aria-invalid={Boolean(errors.fixed_asset_category_id) || undefined}
              >
                <option value="">Select a category…</option>
                {activeCategories.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.code} — {category.name} ({category.useful_life_months} mo)
                  </option>
                ))}
              </select>
            </FormField>
          </div>
        </section>

        <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
          <h2 className="text-base font-semibold text-foreground">Asset details</h2>

          <FormField label="Name" htmlFor="fa-name" required error={errors.name}>
            <Input
              id="fa-name"
              value={form.name}
              onChange={(event) => set("name", event.target.value)}
              invalid={Boolean(errors.name)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Description" htmlFor="fa-description" error={errors.description}>
            <textarea
              id="fa-description"
              className={textareaClasses}
              value={form.description}
              onChange={(event) => set("description", event.target.value)}
            />
          </FormField>

          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Serial number" htmlFor="fa-serial" error={errors.serial_number}>
              <Input
                id="fa-serial"
                value={form.serial_number}
                onChange={(event) => set("serial_number", event.target.value)}
                invalid={Boolean(errors.serial_number)}
                autoComplete="off"
              />
            </FormField>

            <FormField label="Supplier reference" htmlFor="fa-supplier-ref" error={errors.supplier_reference}>
              <Input
                id="fa-supplier-ref"
                value={form.supplier_reference}
                onChange={(event) => set("supplier_reference", event.target.value)}
                invalid={Boolean(errors.supplier_reference)}
                autoComplete="off"
              />
            </FormField>
          </div>
        </section>

        <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
          <h2 className="text-base font-semibold text-foreground">Dates and cost</h2>

          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Acquisition date" htmlFor="fa-acquisition-date" required error={errors.acquisition_date}>
              <Input
                id="fa-acquisition-date"
                type="date"
                value={form.acquisition_date}
                onChange={(event) => set("acquisition_date", event.target.value)}
                invalid={Boolean(errors.acquisition_date)}
              />
            </FormField>

            <FormField
              label="Depreciation start date"
              htmlFor="fa-depreciation-start"
              required
              error={errors.depreciation_start_date}
            >
              <Input
                id="fa-depreciation-start"
                type="date"
                value={form.depreciation_start_date}
                onChange={(event) => set("depreciation_start_date", event.target.value)}
                invalid={Boolean(errors.depreciation_start_date)}
              />
            </FormField>

            <FormField label="Original cost" htmlFor="fa-original-cost" required error={errors.original_cost}>
              <Input
                id="fa-original-cost"
                inputMode="decimal"
                value={form.original_cost}
                onChange={(event) => set("original_cost", event.target.value)}
                invalid={Boolean(errors.original_cost)}
                autoComplete="off"
              />
            </FormField>

            <FormField
              label="Residual (salvage) value"
              htmlFor="fa-salvage"
              error={errors.salvage_value}
              hint="Optional. Defaults to zero."
            >
              <Input
                id="fa-salvage"
                inputMode="decimal"
                value={form.salvage_value}
                onChange={(event) => set("salvage_value", event.target.value)}
                invalid={Boolean(errors.salvage_value)}
                autoComplete="off"
              />
            </FormField>
          </div>

          <FormField
            label={acquisitionAccountLabel}
            htmlFor="fa-acquisition-account"
            required
            error={errors.acquisition_account_id}
          >
            <select
              id="fa-acquisition-account"
              className={selectClasses}
              value={form.acquisition_account_id}
              onChange={(event) => set("acquisition_account_id", event.target.value)}
              disabled={accountsState.status !== "ready"}
              aria-invalid={Boolean(errors.acquisition_account_id) || undefined}
            >
              <option value="">Select an account…</option>
              {accounts.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.code} — {account.name}
                  {account.is_active ? "" : " (inactive)"}
                </option>
              ))}
            </select>
          </FormField>
        </section>

        <div className="flex flex-wrap gap-2">
          <Button type="submit" loading={submitting} disabled={submitting}>
            {isEdit ? "Save changes" : "Create asset"}
          </Button>
          <Link href={isEdit && asset ? `/fixed-assets/${asset.id}` : "/fixed-assets"}>
            <Button type="button" variant="secondary" disabled={submitting}>
              Cancel
            </Button>
          </Link>
        </div>
      </form>
    </div>
  );
}
