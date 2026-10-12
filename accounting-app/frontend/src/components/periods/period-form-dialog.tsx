"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { ApiError } from "@/lib/api/errors";
import type { FinancialYearOption } from "@/lib/financial-years/types";

import { createAccountingPeriod, updateAccountingPeriod } from "@/lib/periods/api";
import type {
  AccountingPeriod,
  PeriodUpdatePayload,
  PeriodWritePayload,
} from "@/lib/periods/types";
import { validatePeriodWrite } from "@/lib/periods/validators";

/**
 * Create / edit accounting-period dialog.
 *
 * On create the period may be attached to one of the company's financial years
 * or left unattached (`financial_year_id` omitted), in which case the backend
 * derives the year from its configured calendar. On edit the year cannot be
 * moved (`UpdatePeriodRequest` carries no `financial_year_id`), and a closed
 * period cannot be edited at all — the backend refuses both, and its message is
 * shown verbatim.
 */

type EditorMode = { mode: "create" } | { mode: "edit"; period: AccountingPeriod };

type PeriodFormDialogProps = {
  editor: EditorMode | null;
  onClose: () => void;
  onSaved: () => Promise<void> | void;
  yearOptions: FinancialYearOption[];
};

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function toFieldErrors(client: Record<string, string>): Record<string, string[]> {
  return Object.fromEntries(Object.entries(client).map(([key, value]) => [key, [value]]));
}

export function PeriodFormDialog({
  editor,
  onClose,
  onSaved,
  yearOptions,
}: PeriodFormDialogProps) {
  const isEdit = editor?.mode === "edit";
  const period = isEdit ? editor.period : null;
  const open = editor !== null;

  const [name, setName] = useState(period?.name ?? "");
  const [startDate, setStartDate] = useState(period?.start_date ?? "");
  const [endDate, setEndDate] = useState(period?.end_date ?? "");
  const [financialYearId, setFinancialYearId] = useState<string>(
    period?.financial_year_id != null ? String(period.financial_year_id) : "",
  );
  const [pending, setPending] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFieldErrors({});
    setFormError(null);

    const base = {
      name: name.trim(),
      start_date: startDate.trim(),
      end_date: endDate.trim(),
    };

    const payload: PeriodWritePayload | PeriodUpdatePayload =
      period === null
        ? { ...base, financial_year_id: financialYearId === "" ? null : Number(financialYearId) }
        : base;

    const clientErrors = validatePeriodWrite(payload);

    if (Object.keys(clientErrors).length > 0) {
      setFieldErrors(toFieldErrors(clientErrors));
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setPending(true);

    try {
      if (period === null) {
        await createAccountingPeriod(payload as PeriodWritePayload);
      } else {
        await updateAccountingPeriod(period.id, payload as PeriodUpdatePayload);
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
      title={isEdit ? `Edit ${period?.name ?? "period"}` : "New accounting period"}
      description={
        isEdit
          ? "A closed period cannot be modified. Reopen it first if a change is required."
          : "A dated posting window. The backend refuses dates that overlap another period."
      }
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="period-form" loading={pending}>
            {isEdit ? "Save changes" : "Create period"}
          </Button>
        </>
      }
    >
      <form id="period-form" onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
        {formError ? (
          <ErrorState title="Could not save the period" message={formError} />
        ) : null}

        <FormField label="Name" htmlFor="period-name" required error={fieldErrors.name?.[0]}>
          <Input
            id="period-name"
            name="name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            invalid={Boolean(fieldErrors.name)}
            aria-describedby={fieldErrors.name ? "period-name-error" : undefined}
            maxLength={255}
            autoComplete="off"
          />
        </FormField>

        <FormField
          label="Start date"
          htmlFor="period-start"
          required
          error={fieldErrors.start_date?.[0]}
        >
          <Input
            id="period-start"
            name="start_date"
            type="date"
            value={startDate}
            onChange={(event) => setStartDate(event.target.value)}
            invalid={Boolean(fieldErrors.start_date)}
            aria-describedby={fieldErrors.start_date ? "period-start-error" : undefined}
          />
        </FormField>

        <FormField
          label="End date"
          htmlFor="period-end"
          required
          error={fieldErrors.end_date?.[0]}
        >
          <Input
            id="period-end"
            name="end_date"
            type="date"
            value={endDate}
            onChange={(event) => setEndDate(event.target.value)}
            invalid={Boolean(fieldErrors.end_date)}
            aria-describedby={fieldErrors.end_date ? "period-end-error" : undefined}
          />
        </FormField>

        {period === null ? (
          <FormField
            label="Financial year"
            htmlFor="period-financial-year"
            hint="Optional. Leave unattached and the backend will derive the year from the company calendar."
            error={fieldErrors.financial_year_id?.[0]}
          >
            <select
              id="period-financial-year"
              name="financial_year_id"
              className={`${selectClasses} ${fieldErrors.financial_year_id ? "border-danger" : ""}`}
              value={financialYearId}
              onChange={(event) => setFinancialYearId(event.target.value)}
              aria-invalid={Boolean(fieldErrors.financial_year_id)}
              aria-describedby={
                fieldErrors.financial_year_id ? "period-financial-year-error" : undefined
              }
            >
              <option value="">Derive from company calendar</option>
              {yearOptions.map((option) => (
                <option key={option.id} value={String(option.id)}>
                  {option.name}
                </option>
              ))}
            </select>
          </FormField>
        ) : null}
      </form>
    </Dialog>
  );
}
