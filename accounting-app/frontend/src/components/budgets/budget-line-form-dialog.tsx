"use client";

import { useMemo, useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FormField, Input } from "@/components/ui/field";
import { useAccountTypeOptions } from "@/lib/accounts/use-account-type-options";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { createBudgetLine, updateBudgetLine } from "@/lib/budgets/api";
import type {
  Budget,
  BudgetLine,
  BudgetLineCreatePayload,
  BudgetLineDimensionAssignment,
} from "@/lib/budgets/types";
import { parseBudgetLineOrThrow, validateBudgetLineCreate } from "@/lib/budgets/validators";
import { useFinancialDimensionOptions } from "@/lib/dimensions/use-dimension-options";
import { useAccountingPeriodOptions } from "@/lib/periods/use-period-options";

import { BudgetLineDimensionsField } from "./budget-line-dimensions-field";

/**
 * Create/edit a budget line: account, period, planned amount, and optional
 * analytical labels.
 *
 * A line may only reference a Revenue or Expense account that is active, and a
 * period inside the budget's own financial year — so the account picker offers
 * only eligible types and the period picker is scoped to that year. A line's
 * `dimensions` are the whole set of labels: a present list (even empty) replaces
 * what was stored. The planned `amount` stays a decimal string; nothing is
 * converted to a JavaScript number and no total is derived here.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

const textareaClasses =
  "min-h-16 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type BudgetLineForm = {
  account_id: string;
  accounting_period_id: string;
  amount: string;
  description: string;
};

function toForm(line: BudgetLine | null): BudgetLineForm {
  if (line === null) {
    return { account_id: "", accounting_period_id: "", amount: "", description: "" };
  }
  return {
    account_id: String(line.account_id),
    accounting_period_id: String(line.accounting_period_id),
    amount: line.amount,
    description: line.description ?? "",
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

type BudgetLineFormDialogProps = {
  open: boolean;
  companyId: number | null;
  budget: Budget;
  /** Present when editing; absent when creating. */
  line?: BudgetLine | null;
  onClose: () => void;
  onSaved: (line: BudgetLine) => void;
};

export function BudgetLineFormDialog({
  open,
  companyId,
  budget,
  line,
  onClose,
  onSaved,
}: BudgetLineFormDialogProps) {
  const isEdit = Boolean(line);

  const accountsState = useAccountTypeOptions(companyId, open && companyId !== null, 0);
  const eligibleAccounts =
    accountsState.status === "ready"
      ? accountsState.items.filter(
          (account) => account.account_type === "REVENUE" || account.account_type === "EXPENSE",
        )
      : [];

  const periodsState = useAccountingPeriodOptions(budget.financial_year_id, open, 0);
  const periods = periodsState.status === "ready" ? periodsState.items : [];

  const dimensionsState = useFinancialDimensionOptions(companyId, open && companyId !== null, 0);
  const activeDimensions = useMemo(
    () => (dimensionsState.status === "ready" ? dimensionsState.items.filter((dimension) => dimension.is_active) : []),
    [dimensionsState],
  );

  const [form, setForm] = useState<BudgetLineForm>(() => toForm(line ?? null));
  const [assignments, setAssignments] = useState<BudgetLineDimensionAssignment[]>(
    () => line?.dimensions ?? [],
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const activeDimensionIds = useMemo(
    () => new Set(activeDimensions.map((dimension) => dimension.id)),
    [activeDimensions],
  );

  /** A stored label on a dimension that has since been retired cannot be kept. */
  const staleAssignments = useMemo(
    () => (line?.dimensions ?? []).filter((assignment) => !activeDimensionIds.has(assignment.dimension_id)),
    [line, activeDimensionIds],
  );

  const set = <K extends keyof BudgetLineForm>(key: K, value: BudgetLineForm[K]) => {
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

    const accountId = toId(form.account_id);
    const periodId = toId(form.accounting_period_id);
    const amount = form.amount.trim();

    // Only the labels on an active dimension survive the save.
    const managedDimensions = assignments.filter((assignment) =>
      activeDimensionIds.has(assignment.dimension_id),
    );

    const payload: BudgetLineCreatePayload = {
      account_id: accountId,
      accounting_period_id: periodId,
      amount,
      description: orNull(form.description),
      dimensions: managedDimensions,
    };

    const clientErrors = validateBudgetLineCreate(payload);
    if (
      accountsState.status === "ready" &&
      !eligibleAccounts.some((account) => account.id === accountId && account.is_active)
    ) {
      clientErrors.account_id = "Choose an active revenue or expense account.";
    }
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const raw = isEdit && line
        ? await updateBudgetLine(budget.id, line.id, payload)
        : await createBudgetLine(budget.id, payload);
      onSaved(parseBudgetLineOrThrow(raw));
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this line. Please try again.");
      }
      setSubmitting(false);
    }
  };

  const storedAccountMissing =
    isEdit &&
    accountsState.status === "ready" &&
    !eligibleAccounts.some((account) => account.id === toId(form.account_id));

  return (
    <Dialog
      open={open}
      title={isEdit ? "Edit budget line" : "New budget line"}
      onClose={onClose}
      className="max-w-2xl"
      footer={
        <>
          <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="budget-line-form" loading={submitting}>
            {isEdit ? "Save changes" : "Add line"}
          </Button>
        </>
      }
    >
      <form id="budget-line-form" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {formError ? (
          <p
            role="alert"
            className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
          >
            {formError}
          </p>
        ) : null}

        {staleAssignments.length > 0 ? (
          <p className="rounded-md border border-warning-border bg-warning-soft px-3 py-2 text-xs text-warning-soft-foreground">
            This line carries a label on a dimension that is no longer active. It cannot be kept, so saving will
            remove it.
          </p>
        ) : null}

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Account" htmlFor="budget-line-account" required error={errors.account_id}>
            <select
              id="budget-line-account"
              className={selectClasses}
              value={form.account_id}
              onChange={(event) => set("account_id", event.target.value)}
              disabled={accountsState.status !== "ready"}
            >
              <option value="">Select an account…</option>
              {storedAccountMissing ? (
                <option value={form.account_id} disabled>
                  (current account is inactive)
                </option>
              ) : null}
              {eligibleAccounts.map((account) => (
                <option key={account.id} value={account.id} disabled={!account.is_active}>
                  {account.code} — {account.name}
                  {account.is_active ? "" : " (inactive)"}
                </option>
              ))}
            </select>
          </FormField>

          <FormField label="Period" htmlFor="budget-line-period" required error={errors.accounting_period_id}>
            <select
              id="budget-line-period"
              className={selectClasses}
              value={form.accounting_period_id}
              onChange={(event) => set("accounting_period_id", event.target.value)}
              disabled={periodsState.status !== "ready"}
            >
              <option value="">Select a period…</option>
              {periods.map((period) => (
                <option key={period.id} value={period.id}>
                  {period.name}
                </option>
              ))}
            </select>
          </FormField>
        </div>

        <FormField
          label="Planned amount"
          htmlFor="budget-line-amount"
          required
          error={errors.amount}
          hint="A non-negative amount in the company base currency."
        >
          <Input
            id="budget-line-amount"
            inputMode="decimal"
            value={form.amount}
            onChange={(event) => set("amount", event.target.value)}
            invalid={Boolean(errors.amount)}
            autoComplete="off"
          />
        </FormField>

        <FormField label="Description" htmlFor="budget-line-description" error={errors.description}>
          <textarea
            id="budget-line-description"
            className={textareaClasses}
            value={form.description}
            onChange={(event) => set("description", event.target.value)}
          />
        </FormField>

        <fieldset className="flex flex-col gap-3">
          <legend className="text-sm font-medium text-foreground">Dimensions</legend>
          <BudgetLineDimensionsField
            dimensions={activeDimensions}
            value={assignments}
            onChange={setAssignments}
            disabled={submitting}
          />
        </fieldset>
      </form>
    </Dialog>
  );
}
