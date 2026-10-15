"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FormField, Input } from "@/components/ui/field";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { createFinancialDimensionValue, updateFinancialDimensionValue } from "@/lib/dimensions/api";
import type { FinancialDimensionValue, FinancialDimensionValueWritePayload } from "@/lib/dimensions/types";
import {
  parseFinancialDimensionValueOrThrow,
  validateFinancialDimensionValueWrite,
} from "@/lib/dimensions/validators";

/**
 * Create/edit a value under a dimension.
 *
 * A value is a single member of the axis ("CC-001 Sales" under a cost centre).
 * It has no company of its own — it is addressed through its parent dimension, so
 * that a foreign id both fails the tenant check and the nesting check in one
 * lookup. Creating a value requires the parent dimension to be active; the
 * backend refuses otherwise and the reason is shown verbatim.
 */

type DimensionValueFormProps = {
  open: boolean;
  dimensionId: number;
  /** Present when editing; absent when creating. */
  value?: FinancialDimensionValue | null;
  onClose: () => void;
  onSaved: (value: FinancialDimensionValue) => void;
};

export function DimensionValueFormDialog({
  open,
  dimensionId,
  value,
  onClose,
  onSaved,
}: DimensionValueFormProps) {
  const isEdit = Boolean(value);

  const [code, setCode] = useState(value?.code ?? "");
  const [name, setName] = useState(value?.name ?? "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const clearError = (key: string) => {
    setErrors((current) => {
      if (!(key in current)) return current;
      const next = { ...current };
      delete next[key];
      return next;
    });
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;

    const payload: FinancialDimensionValueWritePayload = { code: code.trim(), name: name.trim() };

    const clientErrors = validateFinancialDimensionValueWrite(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const raw =
        isEdit && value
          ? await updateFinancialDimensionValue(dimensionId, value.id, payload)
          : await createFinancialDimensionValue(dimensionId, payload);
      onSaved(parseFinancialDimensionValueOrThrow(raw));
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this value. Please try again.");
      }
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title={isEdit ? "Edit value" : "New value"}
      onClose={onClose}
      footer={
        <>
          <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="dimension-value-form" loading={submitting}>
            {isEdit ? "Save changes" : "Create value"}
          </Button>
        </>
      }
    >
      <form id="dimension-value-form" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {formError ? (
          <p
            role="alert"
            className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
          >
            {formError}
          </p>
        ) : null}

        <FormField label="Code" htmlFor="dimension-value-code" required error={errors.code}>
          <Input
            id="dimension-value-code"
            value={code}
            onChange={(event) => {
              setCode(event.target.value);
              clearError("code");
            }}
            invalid={Boolean(errors.code)}
            autoComplete="off"
          />
        </FormField>

        <FormField label="Name" htmlFor="dimension-value-name" required error={errors.name}>
          <Input
            id="dimension-value-name"
            value={name}
            onChange={(event) => {
              setName(event.target.value);
              clearError("name");
            }}
            invalid={Boolean(errors.name)}
            autoComplete="off"
          />
        </FormField>
      </form>
    </Dialog>
  );
}
