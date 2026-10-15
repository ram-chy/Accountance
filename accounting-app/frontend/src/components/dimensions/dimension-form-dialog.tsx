"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FormField, Input } from "@/components/ui/field";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { createFinancialDimension, updateFinancialDimension } from "@/lib/dimensions/api";
import {
  FINANCIAL_DIMENSION_TYPE_LABELS,
  FINANCIAL_DIMENSION_TYPE_VALUES,
  type FinancialDimension,
  type FinancialDimensionCreatePayload,
  type FinancialDimensionTypeValue,
  type FinancialDimensionUpdatePayload,
} from "@/lib/dimensions/types";
import {
  parseFinancialDimensionOrThrow,
  validateFinancialDimensionCreate,
  validateFinancialDimensionUpdate,
} from "@/lib/dimensions/validators";

/**
 * Create/edit a financial dimension.
 *
 * A dimension is one analytical axis the ledger may be cut along. Its `type` is
 * a controlled vocabulary chosen at creation and never changed afterwards — the
 * backend's update request accepts only `code`/`name` — so it is shown read-only
 * when editing. The company and active flag are server-owned and never sent.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type DimensionForm = {
  type: FinancialDimensionTypeValue;
  code: string;
  name: string;
};

const EMPTY_FORM: DimensionForm = {
  type: "COST_CENTER",
  code: "",
  name: "",
};

function toForm(dimension: FinancialDimension): DimensionForm {
  return { type: dimension.type, code: dimension.code, name: dimension.name };
}

type DimensionFormDialogProps = {
  open: boolean;
  /** Present when editing; absent when creating. */
  dimension?: FinancialDimension | null;
  onClose: () => void;
  onSaved: (dimension: FinancialDimension) => void;
};

export function DimensionFormDialog({ open, dimension, onClose, onSaved }: DimensionFormDialogProps) {
  const isEdit = Boolean(dimension);

  const [form, setForm] = useState<DimensionForm>(() => (dimension ? toForm(dimension) : EMPTY_FORM));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const set = <K extends keyof DimensionForm>(key: K, value: DimensionForm[K]) => {
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

    const base = { code: form.code.trim(), name: form.name.trim() };

    const clientErrors = isEdit
      ? validateFinancialDimensionUpdate(base as FinancialDimensionUpdatePayload)
      : validateFinancialDimensionCreate({ ...base, type: form.type } as FinancialDimensionCreatePayload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const raw =
        isEdit && dimension
          ? await updateFinancialDimension(dimension.id, base as FinancialDimensionUpdatePayload)
          : await createFinancialDimension({ ...base, type: form.type } as FinancialDimensionCreatePayload);
      onSaved(parseFinancialDimensionOrThrow(raw));
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this dimension. Please try again.");
      }
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title={isEdit ? "Edit dimension" : "New dimension"}
      onClose={onClose}
      footer={
        <>
          <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="dimension-form" loading={submitting}>
            {isEdit ? "Save changes" : "Create dimension"}
          </Button>
        </>
      }
    >
      <form id="dimension-form" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {formError ? (
          <p
            role="alert"
            className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
          >
            {formError}
          </p>
        ) : null}

        <FormField
          label="Type"
          htmlFor="dimension-type"
          required
          error={errors.type}
          hint={isEdit ? "The axis is fixed at creation and cannot be changed." : undefined}
        >
          <select
            id="dimension-type"
            className={selectClasses}
            value={form.type}
            onChange={(event) => set("type", event.target.value as FinancialDimensionTypeValue)}
            disabled={isEdit}
          >
            {FINANCIAL_DIMENSION_TYPE_VALUES.map((type) => (
              <option key={type} value={type}>
                {FINANCIAL_DIMENSION_TYPE_LABELS[type]}
              </option>
            ))}
          </select>
        </FormField>

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Code" htmlFor="dimension-code" required error={errors.code}>
            <Input
              id="dimension-code"
              value={form.code}
              onChange={(event) => set("code", event.target.value)}
              invalid={Boolean(errors.code)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Name" htmlFor="dimension-name" required error={errors.name}>
            <Input
              id="dimension-name"
              value={form.name}
              onChange={(event) => set("name", event.target.value)}
              invalid={Boolean(errors.name)}
              autoComplete="off"
            />
          </FormField>
        </div>
      </form>
    </Dialog>
  );
}
