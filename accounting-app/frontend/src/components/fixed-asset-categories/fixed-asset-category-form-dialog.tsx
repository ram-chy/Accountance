"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FormField, Input } from "@/components/ui/field";
import { useAccountOptions } from "@/lib/accounts/use-account-options";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import {
  createFixedAssetCategory,
  updateFixedAssetCategory,
} from "@/lib/fixed-asset-categories/api";
import type {
  FixedAssetCategory,
  FixedAssetCategoryCreatePayload,
  FixedAssetCategoryUpdatePayload,
} from "@/lib/fixed-asset-categories/types";
import {
  parseFixedAssetCategoryOrThrow,
  validateFixedAssetCategoryCreate,
  validateFixedAssetCategoryUpdate,
} from "@/lib/fixed-asset-categories/validators";

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

const textareaClasses =
  "min-h-20 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

/** Form-shaped strings, converted to the validated payload on submit. */
type CategoryForm = {
  code: string;
  name: string;
  description: string;
  useful_life_months: string;
  asset_account_id: string;
  accumulated_depreciation_account_id: string;
  depreciation_expense_account_id: string;
  gain_on_disposal_account_id: string;
  loss_on_disposal_account_id: string;
};

const EMPTY_FORM: CategoryForm = {
  code: "",
  name: "",
  description: "",
  useful_life_months: "60",
  asset_account_id: "",
  accumulated_depreciation_account_id: "",
  depreciation_expense_account_id: "",
  gain_on_disposal_account_id: "",
  loss_on_disposal_account_id: "",
};

function toForm(category: FixedAssetCategory): CategoryForm {
  return {
    code: category.code,
    name: category.name,
    description: category.description ?? "",
    useful_life_months: String(category.useful_life_months),
    asset_account_id: String(category.asset_account_id),
    accumulated_depreciation_account_id: String(category.accumulated_depreciation_account_id),
    depreciation_expense_account_id: String(category.depreciation_expense_account_id),
    gain_on_disposal_account_id:
      category.gain_on_disposal_account_id === null ? "" : String(category.gain_on_disposal_account_id),
    loss_on_disposal_account_id:
      category.loss_on_disposal_account_id === null ? "" : String(category.loss_on_disposal_account_id),
  };
}

function orNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function toId(value: string): number {
  const parsed = Number.parseInt(value, 10);
  return Number.isInteger(parsed) ? parsed : 0;
}

function toOptionalId(value: string): number | null {
  return value === "" ? null : toId(value);
}

type FixedAssetCategoryFormDialogProps = {
  open: boolean;
  companyId: number | null;
  /** Present when editing; absent when creating. */
  category?: FixedAssetCategory | null;
  onClose: () => void;
  onSaved: (category: FixedAssetCategory) => void;
};

/**
 * Create/edit a fixed-asset category.
 *
 * The category carries the accounting defaults an asset inherits: useful life,
 * the depreciation method (the backend supports only `STRAIGHT_LINE`), and the
 * asset / accumulated-depreciation / expense accounts plus the optional
 * gain-on-disposal and loss-on-disposal accounts. Server validation maps back to
 * the relevant field.
 */
export function FixedAssetCategoryFormDialog({
  open,
  companyId,
  category,
  onClose,
  onSaved,
}: FixedAssetCategoryFormDialogProps) {
  const isEdit = Boolean(category);
  const accountsState = useAccountOptions(companyId, true);
  const accounts = accountsState.status === "ready" ? accountsState.items : [];

  const [form, setForm] = useState<CategoryForm>(() => (category ? toForm(category) : EMPTY_FORM));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const set = <K extends keyof CategoryForm>(key: K, value: CategoryForm[K]) => {
    setForm((current) => ({ ...current, [key]: value }));
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
      code: form.code.trim(),
      name: form.name.trim(),
      description: orNull(form.description),
      useful_life_months: toId(form.useful_life_months),
      asset_account_id: toId(form.asset_account_id),
      accumulated_depreciation_account_id: toId(form.accumulated_depreciation_account_id),
      depreciation_expense_account_id: toId(form.depreciation_expense_account_id),
      gain_on_disposal_account_id: toOptionalId(form.gain_on_disposal_account_id),
      loss_on_disposal_account_id: toOptionalId(form.loss_on_disposal_account_id),
    };

    const createPayload: FixedAssetCategoryCreatePayload = {
      ...base,
      depreciation_method: "STRAIGHT_LINE",
    };

    const clientErrors = isEdit
      ? validateFixedAssetCategoryUpdate(base as FixedAssetCategoryUpdatePayload)
      : validateFixedAssetCategoryCreate(createPayload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const raw =
        isEdit && category
          ? await updateFixedAssetCategory(category.id, base as FixedAssetCategoryUpdatePayload)
          : await createFixedAssetCategory(createPayload);
      const saved = parseFixedAssetCategoryOrThrow(raw);
      onSaved(saved);
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this category. Please try again.");
      }
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title={isEdit ? "Edit category" : "New category"}
      onClose={onClose}
      className="max-w-2xl"
      footer={
        <>
          <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="fa-category-form" loading={submitting}>
            {isEdit ? "Save changes" : "Create category"}
          </Button>
        </>
      }
    >
      <form id="fa-category-form" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {formError ? (
          <p
            role="alert"
            className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
          >
            {formError}
          </p>
        ) : null}

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Code" htmlFor="fa-category-code" required error={errors.code}>
            <Input
              id="fa-category-code"
              value={form.code}
              onChange={(event) => set("code", event.target.value)}
              invalid={Boolean(errors.code)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Name" htmlFor="fa-category-name" required error={errors.name}>
            <Input
              id="fa-category-name"
              value={form.name}
              onChange={(event) => set("name", event.target.value)}
              invalid={Boolean(errors.name)}
              autoComplete="off"
            />
          </FormField>
        </div>

        <FormField label="Description" htmlFor="fa-category-description" error={errors.description}>
          <textarea
            id="fa-category-description"
            className={textareaClasses}
            value={form.description}
            onChange={(event) => set("description", event.target.value)}
          />
        </FormField>

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField
            label="Useful life (months)"
            htmlFor="fa-category-life"
            required
            error={errors.useful_life_months}
          >
            <Input
              id="fa-category-life"
              type="number"
              min={1}
              value={form.useful_life_months}
              onChange={(event) => set("useful_life_months", event.target.value)}
              invalid={Boolean(errors.useful_life_months)}
            />
          </FormField>

          <FormField
            label="Depreciation method"
            htmlFor="fa-category-method"
            hint="The backend currently supports straight-line only."
          >
            <Input id="fa-category-method" value="Straight line" readOnly disabled />
          </FormField>
        </div>

        <FormField
          label="Asset account"
          htmlFor="fa-category-asset-account"
          required
          error={errors.asset_account_id}
          hint="The account that holds the asset's cost."
        >
          <select
            id="fa-category-asset-account"
            className={selectClasses}
            value={form.asset_account_id}
            onChange={(event) => set("asset_account_id", event.target.value)}
            disabled={accountsState.status !== "ready"}
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

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField
            label="Accumulated depreciation account"
            htmlFor="fa-category-accumulated"
            required
            error={errors.accumulated_depreciation_account_id}
            hint="The contra-asset account that holds accumulated depreciation."
          >
            <select
              id="fa-category-accumulated"
              className={selectClasses}
              value={form.accumulated_depreciation_account_id}
              onChange={(event) => set("accumulated_depreciation_account_id", event.target.value)}
              disabled={accountsState.status !== "ready"}
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

          <FormField
            label="Depreciation expense account"
            htmlFor="fa-category-expense"
            required
            error={errors.depreciation_expense_account_id}
          >
            <select
              id="fa-category-expense"
              className={selectClasses}
              value={form.depreciation_expense_account_id}
              onChange={(event) => set("depreciation_expense_account_id", event.target.value)}
              disabled={accountsState.status !== "ready"}
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
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField
            label="Gain on disposal account"
            htmlFor="fa-category-gain"
            error={errors.gain_on_disposal_account_id}
            hint="Optional. Used when disposal proceeds exceed book value."
          >
            <select
              id="fa-category-gain"
              className={selectClasses}
              value={form.gain_on_disposal_account_id}
              onChange={(event) => set("gain_on_disposal_account_id", event.target.value)}
              disabled={accountsState.status !== "ready"}
            >
              <option value="">None</option>
              {accounts.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.code} — {account.name}
                  {account.is_active ? "" : " (inactive)"}
                </option>
              ))}
            </select>
          </FormField>

          <FormField
            label="Loss on disposal account"
            htmlFor="fa-category-loss"
            error={errors.loss_on_disposal_account_id}
            hint="Optional. Used when book value exceeds disposal proceeds."
          >
            <select
              id="fa-category-loss"
              className={selectClasses}
              value={form.loss_on_disposal_account_id}
              onChange={(event) => set("loss_on_disposal_account_id", event.target.value)}
              disabled={accountsState.status !== "ready"}
            >
              <option value="">None</option>
              {accounts.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.code} — {account.name}
                  {account.is_active ? "" : " (inactive)"}
                </option>
              ))}
            </select>
          </FormField>
        </div>
      </form>
    </Dialog>
  );
}
