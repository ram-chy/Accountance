"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { ApiError } from "@/lib/api/errors";

import { createFinancialYear, updateFinancialYear } from "@/lib/financial-years/api";
import type { FinancialYear, FinancialYearWritePayload } from "@/lib/financial-years/types";
import { validateFinancialYearWrite } from "@/lib/financial-years/validators";

/**
 * Create / edit financial-year dialog.
 *
 * Controlled inputs keep the user's entries across a failed submit; the parent
 * remounts this component (via `key`) when switching between records or modes,
 * which resets state cleanly. Validation mirrors the verified Laravel rules for
 * immediate feedback; the backend remains authoritative and its 422 field errors
 * (duplicate name, overlapping dates) are shown verbatim. Server-owned fields
 * are never sent.
 */

type EditorMode = { mode: "create" } | { mode: "edit"; year: FinancialYear };

type FinancialYearFormDialogProps = {
  editor: EditorMode | null;
  onClose: () => void;
  onSaved: () => Promise<void> | void;
};

function toFieldErrors(client: Record<string, string>): Record<string, string[]> {
  return Object.fromEntries(Object.entries(client).map(([key, value]) => [key, [value]]));
}

export function FinancialYearFormDialog({
  editor,
  onClose,
  onSaved,
}: FinancialYearFormDialogProps) {
  const isEdit = editor?.mode === "edit";
  const year = isEdit ? editor.year : null;
  const open = editor !== null;

  const [name, setName] = useState(year?.name ?? "");
  const [startDate, setStartDate] = useState(year?.start_date ?? "");
  const [endDate, setEndDate] = useState(year?.end_date ?? "");
  const [pending, setPending] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFieldErrors({});
    setFormError(null);

    const payload: FinancialYearWritePayload = {
      name: name.trim(),
      start_date: startDate.trim(),
      end_date: endDate.trim(),
    };

    const clientErrors = validateFinancialYearWrite(payload);

    if (Object.keys(clientErrors).length > 0) {
      setFieldErrors(toFieldErrors(clientErrors));
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setPending(true);

    try {
      if (year === null) {
        await createFinancialYear(payload);
      } else {
        await updateFinancialYear(year.id, payload);
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
      title={isEdit ? `Edit ${year?.name ?? "financial year"}` : "New financial year"}
      description={
        isEdit
          ? "A financial year that is already closed cannot be modified."
          : "A dated container for accounting periods. Closing it is a separate step."
      }
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="financial-year-form" loading={pending}>
            {isEdit ? "Save changes" : "Create financial year"}
          </Button>
        </>
      }
    >
      <form
        id="financial-year-form"
        onSubmit={handleSubmit}
        noValidate
        className="flex flex-col gap-4"
      >
        {formError ? (
          <ErrorState title="Could not save the financial year" message={formError} />
        ) : null}

        <FormField
          label="Name"
          htmlFor="financial-year-name"
          required
          hint="For example “2026-2027”. Must be unique within the company."
          error={fieldErrors.name?.[0]}
        >
          <Input
            id="financial-year-name"
            name="name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            invalid={Boolean(fieldErrors.name)}
            aria-describedby={fieldErrors.name ? "financial-year-name-error" : undefined}
            maxLength={255}
            autoComplete="off"
          />
        </FormField>

        <FormField
          label="Start date"
          htmlFor="financial-year-start"
          required
          error={fieldErrors.start_date?.[0]}
        >
          <Input
            id="financial-year-start"
            name="start_date"
            type="date"
            value={startDate}
            onChange={(event) => setStartDate(event.target.value)}
            invalid={Boolean(fieldErrors.start_date)}
            aria-describedby={fieldErrors.start_date ? "financial-year-start-error" : undefined}
          />
        </FormField>

        <FormField
          label="End date"
          htmlFor="financial-year-end"
          required
          hint="Must not be before the start date, and must not overlap another year."
          error={fieldErrors.end_date?.[0]}
        >
          <Input
            id="financial-year-end"
            name="end_date"
            type="date"
            value={endDate}
            onChange={(event) => setEndDate(event.target.value)}
            invalid={Boolean(fieldErrors.end_date)}
            aria-describedby={fieldErrors.end_date ? "financial-year-end-error" : undefined}
          />
        </FormField>
      </form>
    </Dialog>
  );
}
