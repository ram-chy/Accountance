"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { ApiError } from "@/lib/api/errors";

import { createTax, updateTax } from "@/lib/taxes/api";
import {
  TAX_CALCULATION_BASIS_VALUES,
  TAX_CALCULATION_BASIS_LABELS,
  TAX_TYPE_LABELS,
  TAX_TYPE_VALUES,
  type Tax,
  type TaxCalculationBasisValue,
  type TaxCreatePayload,
  type TaxTypeValue,
} from "@/lib/taxes/types";
import { validateTaxWrite } from "@/lib/taxes/validators";

/**
 * Create / edit tax dialog.
 *
 * Fields mirror `StoreTaxRequest` / `UpdateTaxRequest` exactly: code, name,
 * optional description, `tax_type` (OUTPUT/INPUT/BOTH) and `calculation_basis`
 * (EXCLUSIVE/INCLUSIVE). Server-owned fields — id, `is_active`, the derived side
 * flags and the audit columns — are never sent; activation is its own audited
 * action.
 *
 * Client validation mirrors the request's shape only; the backend re-validates
 * every write and its field-level 422 is shown in place. The form state is
 * remounted by the parent via `key`, so switching between taxes never leaks one
 * record's values into another.
 */

export type TaxEditorMode = { mode: "create" } | { mode: "edit"; tax: Tax };

type TaxFormDialogProps = {
  editor: TaxEditorMode | null;
  onClose: () => void;
  onSaved: () => Promise<void> | void;
};

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function toFieldErrors(client: Record<string, string>): Record<string, string[]> {
  return Object.fromEntries(Object.entries(client).map(([key, value]) => [key, [value]]));
}

export function TaxFormDialog({ editor, onClose, onSaved }: TaxFormDialogProps) {
  const isEdit = editor?.mode === "edit";
  const tax = isEdit ? editor.tax : null;
  const open = editor !== null;

  const [code, setCode] = useState(tax?.code ?? "");
  const [name, setName] = useState(tax?.name ?? "");
  const [description, setDescription] = useState(tax?.description ?? "");
  const [taxType, setTaxType] = useState<TaxTypeValue>(tax?.tax_type ?? "OUTPUT");
  const [basis, setBasis] = useState<TaxCalculationBasisValue>(
    tax?.calculation_basis ?? "EXCLUSIVE",
  );
  const [pending, setPending] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFieldErrors({});
    setFormError(null);

    const payload: TaxCreatePayload = {
      code: code.trim(),
      name: name.trim(),
      description: description.trim() === "" ? null : description.trim(),
      tax_type: taxType,
      calculation_basis: basis,
    };

    const clientErrors = validateTaxWrite(payload);

    if (Object.keys(clientErrors).length > 0) {
      setFieldErrors(toFieldErrors(clientErrors));
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setPending(true);

    try {
      if (tax === null) {
        await createTax(payload);
      } else {
        await updateTax(tax.id, payload);
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
      title={isEdit ? `Edit ${tax?.code ?? "tax"}` : "New tax"}
      description={
        isEdit
          ? "Changing the type or basis affects future documents only. Past postings keep their snapshot."
          : "A code, a name, which side of a transaction it applies to, and whether amounts include it."
      }
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="tax-form" loading={pending}>
            {isEdit ? "Save changes" : "Create tax"}
          </Button>
        </>
      }
    >
      <form id="tax-form" onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
        {formError ? <ErrorState title="Could not save the tax" message={formError} /> : null}

        <FormField label="Code" htmlFor="tax-code" required error={fieldErrors.code?.[0]}>
          <Input
            id="tax-code"
            name="code"
            value={code}
            onChange={(event) => setCode(event.target.value)}
            invalid={Boolean(fieldErrors.code)}
            aria-describedby={fieldErrors.code ? "tax-code-error" : undefined}
            maxLength={50}
            autoComplete="off"
          />
        </FormField>

        <FormField label="Name" htmlFor="tax-name" required error={fieldErrors.name?.[0]}>
          <Input
            id="tax-name"
            name="name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            invalid={Boolean(fieldErrors.name)}
            aria-describedby={fieldErrors.name ? "tax-name-error" : undefined}
            maxLength={255}
            autoComplete="off"
          />
        </FormField>

        <FormField label="Description" htmlFor="tax-description" error={fieldErrors.description?.[0]}>
          <Input
            id="tax-description"
            name="description"
            value={description}
            onChange={(event) => setDescription(event.target.value)}
            invalid={Boolean(fieldErrors.description)}
            aria-describedby={fieldErrors.description ? "tax-description-error" : undefined}
            maxLength={1000}
            autoComplete="off"
          />
        </FormField>

        <FormField
          label="Tax type"
          htmlFor="tax-type"
          required
          hint="Which side of a transaction this tax belongs to. The engine adds it to that side only."
          error={fieldErrors.tax_type?.[0]}
        >
          <select
            id="tax-type"
            name="tax_type"
            className={`${selectClasses} ${fieldErrors.tax_type ? "border-danger" : ""}`}
            value={taxType}
            onChange={(event) => setTaxType(event.target.value as TaxTypeValue)}
            aria-invalid={Boolean(fieldErrors.tax_type)}
          >
            {TAX_TYPE_VALUES.map((value) => (
              <option key={value} value={value}>
                {TAX_TYPE_LABELS[value]}
              </option>
            ))}
          </select>
        </FormField>

        <FormField
          label="Calculation basis"
          htmlFor="tax-basis"
          required
          hint="Whether an amount you enter includes this tax. The configured value is a default the calculator can override."
          error={fieldErrors.calculation_basis?.[0]}
        >
          <select
            id="tax-basis"
            name="calculation_basis"
            className={`${selectClasses} ${fieldErrors.calculation_basis ? "border-danger" : ""}`}
            value={basis}
            onChange={(event) => setBasis(event.target.value as TaxCalculationBasisValue)}
            aria-invalid={Boolean(fieldErrors.calculation_basis)}
          >
            {TAX_CALCULATION_BASIS_VALUES.map((value) => (
              <option key={value} value={value}>
                {TAX_CALCULATION_BASIS_LABELS[value]}
              </option>
            ))}
          </select>
        </FormField>
      </form>
    </Dialog>
  );
}
