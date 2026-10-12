"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { ApiError } from "@/lib/api/errors";

import { createTaxRate, updateTaxRate } from "@/lib/taxes/api";
import type { TaxRate, TaxRateCreatePayload } from "@/lib/taxes/types";
import { validateTaxRateWrite } from "@/lib/taxes/validators";

/**
 * Create / edit a tax rate period.
 *
 * The rate is a percentage entered as a decimal string and sent back exactly as
 * typed — it is never parsed to a float, so `7.5` stays `7.5` and `7.5000` stays
 * `7.5000`. An empty effective-to date means the period is open-ended, which is
 * the ordinary "from April it is 12%" change.
 *
 * Effective-date overlap with the existing history is deliberately not
 * pre-checked: the backend owns that rule (inside a transaction, so it cannot
 * race a concurrent write) and its 422 on the `rate` field is shown verbatim.
 */

export type RateEditorMode = { mode: "create" } | { mode: "edit"; rate: TaxRate };

type TaxRateFormDialogProps = {
  taxId: number | null;
  editor: RateEditorMode | null;
  onClose: () => void;
  onSaved: () => Promise<void> | void;
};

function toFieldErrors(client: Record<string, string>): Record<string, string[]> {
  return Object.fromEntries(Object.entries(client).map(([key, value]) => [key, [value]]));
}

export function TaxRateFormDialog({ taxId, editor, onClose, onSaved }: TaxRateFormDialogProps) {
  const isEdit = editor?.mode === "edit";
  const rate = isEdit ? editor.rate : null;
  const open = editor !== null && taxId !== null;

  const [value, setValue] = useState(rate?.rate ?? "");
  const [effectiveFrom, setEffectiveFrom] = useState(rate?.effective_from ?? "");
  const [effectiveTo, setEffectiveTo] = useState(rate?.effective_to ?? "");
  const [pending, setPending] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (taxId === null) {
      return;
    }

    setFieldErrors({});
    setFormError(null);

    const payload: TaxRateCreatePayload = {
      rate: value.trim(),
      effective_from: effectiveFrom.trim(),
      effective_to: effectiveTo.trim() === "" ? null : effectiveTo.trim(),
    };

    const clientErrors = validateTaxRateWrite(payload);

    if (Object.keys(clientErrors).length > 0) {
      setFieldErrors(toFieldErrors(clientErrors));
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setPending(true);

    try {
      if (rate === null) {
        await createTaxRate(taxId, payload);
      } else {
        await updateTaxRate(taxId, rate.id, payload);
      }
      await onSaved();
      onClose();
    } catch (error) {
      if (error instanceof ApiError && error.isValidation && error.errors) {
        setFieldErrors(error.errors);
        setFormError("Please correct the highlighted fields.");
      } else if (error instanceof ApiError) {
        setFormError(error.message);
      } else {
        setFormError("Something went wrong. Please try again.");
      }
    } finally {
      setPending(false);
    }
  };

  return (
    <Dialog
      open={open}
      title={isEdit ? "Edit tax rate" : "New tax rate"}
      description="A percentage period. A tax has exactly one rate in force on any given day."
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="tax-rate-form" loading={pending}>
            {isEdit ? "Save rate" : "Add rate"}
          </Button>
        </>
      }
    >
      <form id="tax-rate-form" onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
        {formError ? <ErrorState title="Could not save the rate" message={formError} /> : null}

        <FormField
          label="Rate (%)"
          htmlFor="tax-rate-value"
          required
          hint="A percentage: enter 7.5 for 7.5%. Must be between 0 and 100."
          error={fieldErrors.rate?.[0]}
        >
          <Input
            id="tax-rate-value"
            name="rate"
            inputMode="decimal"
            value={value}
            onChange={(event) => setValue(event.target.value)}
            invalid={Boolean(fieldErrors.rate)}
            aria-describedby={fieldErrors.rate ? "tax-rate-value-error" : undefined}
            autoComplete="off"
          />
        </FormField>

        <FormField
          label="Effective from"
          htmlFor="tax-rate-from"
          required
          error={fieldErrors.effective_from?.[0]}
        >
          <Input
            id="tax-rate-from"
            name="effective_from"
            type="date"
            value={effectiveFrom}
            onChange={(event) => setEffectiveFrom(event.target.value)}
            invalid={Boolean(fieldErrors.effective_from)}
            aria-describedby={fieldErrors.effective_from ? "tax-rate-from-error" : undefined}
          />
        </FormField>

        <FormField
          label="Effective to"
          htmlFor="tax-rate-to"
          hint="Optional. Leave empty for an open-ended rate, which closes the previous one the day before."
          error={fieldErrors.effective_to?.[0]}
        >
          <Input
            id="tax-rate-to"
            name="effective_to"
            type="date"
            value={effectiveTo}
            onChange={(event) => setEffectiveTo(event.target.value)}
            invalid={Boolean(fieldErrors.effective_to)}
            aria-describedby={fieldErrors.effective_to ? "tax-rate-to-error" : undefined}
          />
        </FormField>
      </form>
    </Dialog>
  );
}
