"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FormField, Input } from "@/components/ui/field";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { createBudget, updateBudget } from "@/lib/budgets/api";
import type { Budget, BudgetCreatePayload, BudgetUpdatePayload } from "@/lib/budgets/types";
import {
  parseBudgetOrThrow,
  validateBudgetCreate,
  validateBudgetUpdate,
} from "@/lib/budgets/validators";
import { useFinancialYearOptions } from "@/lib/financial-years/use-financial-years";

/**
 * Create/edit a budget.
 *
 * A budget's financial year is chosen once and then fixed — its lines reference
 * that year's periods, so moving it would be a different plan, and the backend's
 * update request does not accept the field. Only a draft may be edited at all; an
 * approved budget is revised into a new version instead. The company, status,
 * version and parent are server-owned and never sent.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

const textareaClasses =
  "min-h-20 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type BudgetForm = {
  financial_year_id: string;
  code: string;
  name: string;
  notes: string;
};

const EMPTY_FORM: BudgetForm = {
  financial_year_id: "",
  code: "",
  name: "",
  notes: "",
};

function toForm(budget: Budget): BudgetForm {
  return {
    financial_year_id: String(budget.financial_year_id),
    code: budget.code,
    name: budget.name,
    notes: budget.notes ?? "",
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

type BudgetFormDialogProps = {
  open: boolean;
  companyId: number | null;
  /** Present when editing; absent when creating. */
  budget?: Budget | null;
  onClose: () => void;
  onSaved: (budget: Budget) => void;
};

export function BudgetFormDialog({ open, companyId, budget, onClose, onSaved }: BudgetFormDialogProps) {
  const isEdit = Boolean(budget);
  const yearsState = useFinancialYearOptions(companyId, open && companyId !== null, 0);
  const years = yearsState.status === "ready" ? yearsState.items : [];

  const [form, setForm] = useState<BudgetForm>(() => (budget ? toForm(budget) : EMPTY_FORM));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const set = <K extends keyof BudgetForm>(key: K, value: BudgetForm[K]) => {
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
      notes: orNull(form.notes),
    };

    if (isEdit) {
      const clientErrors = validateBudgetUpdate(base as BudgetUpdatePayload);
      if (Object.keys(clientErrors).length > 0) {
        setErrors(clientErrors);
        setFormError("Please correct the highlighted fields.");
        return;
      }

      setSubmitting(true);
      setFormError(null);
      try {
        const raw = await updateBudget(budget!.id, base as BudgetUpdatePayload);
        onSaved(parseBudgetOrThrow(raw));
      } catch (error) {
        if (error instanceof ApiError) {
          setErrors(firstFieldErrors(error.errors));
          setFormError(error.message);
        } else {
          setFormError("We could not save this budget. Please try again.");
        }
        setSubmitting(false);
      }
      return;
    }

    const payload: BudgetCreatePayload = {
      financial_year_id: toId(form.financial_year_id),
      code: base.code,
      name: base.name,
      notes: base.notes,
    };

    const clientErrors = validateBudgetCreate(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);
    try {
      const raw = await createBudget(payload);
      onSaved(parseBudgetOrThrow(raw));
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this budget. Please try again.");
      }
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title={isEdit ? "Edit budget" : "New budget"}
      onClose={onClose}
      className="max-w-2xl"
      footer={
        <>
          <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="budget-form" loading={submitting}>
            {isEdit ? "Save changes" : "Create budget"}
          </Button>
        </>
      }
    >
      <form id="budget-form" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {formError ? (
          <p
            role="alert"
            className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
          >
            {formError}
          </p>
        ) : null}

        <FormField
          label="Financial year"
          htmlFor="budget-year"
          required
          error={errors.financial_year_id}
          hint={isEdit ? "The year is fixed at creation and cannot be changed." : undefined}
        >
          <select
            id="budget-year"
            className={selectClasses}
            value={form.financial_year_id}
            onChange={(event) => set("financial_year_id", event.target.value)}
            disabled={isEdit || yearsState.status !== "ready"}
          >
            <option value="">Select a financial year…</option>
            {years.map((year) => (
              <option key={year.id} value={year.id}>
                {year.name}
                {year.status === "CLOSED" ? " (closed)" : ""}
              </option>
            ))}
          </select>
        </FormField>
        {!isEdit && yearsState.status === "error" ? (
          <p className="text-xs text-danger">{yearsState.message ?? "Could not load financial years."}</p>
        ) : null}

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Code" htmlFor="budget-code" required error={errors.code}>
            <Input
              id="budget-code"
              value={form.code}
              onChange={(event) => set("code", event.target.value)}
              invalid={Boolean(errors.code)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Name" htmlFor="budget-name" required error={errors.name}>
            <Input
              id="budget-name"
              value={form.name}
              onChange={(event) => set("name", event.target.value)}
              invalid={Boolean(errors.name)}
              autoComplete="off"
            />
          </FormField>
        </div>

        <FormField label="Notes" htmlFor="budget-notes" error={errors.notes}>
          <textarea
            id="budget-notes"
            className={textareaClasses}
            value={form.notes}
            onChange={(event) => set("notes", event.target.value)}
          />
        </FormField>
      </form>
    </Dialog>
  );
}
