"use client";

import { useState, type FormEvent } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FormField, Input } from "@/components/ui/field";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { approveBudget, deleteBudget, reviseBudget } from "@/lib/budgets/api";
import type { Budget, BudgetRevisePayload } from "@/lib/budgets/types";
import { parseBudgetOrThrow, validateBudgetRevise } from "@/lib/budgets/validators";

/**
 * Lifecycle action dialogs for a single budget.
 *
 * A budget moves DRAFT → APPROVED and, from approved, can be revised into a new
 * draft version. Approval requires at least one line (the backend refuses an
 * empty budget). Revision copies the code, year and lines into a fresh draft and
 * leaves the approved record untouched. Only a draft may be deleted. Each action
 * calls exactly one verified endpoint and surfaces the backend's own refusal
 * verbatim.
 */

const textareaClasses =
  "min-h-16 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function ErrorBanner({ message }: { message: string | null }) {
  if (!message) return null;
  return (
    <p
      role="alert"
      className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
    >
      {message}
    </p>
  );
}

type ApproveBudgetDialogProps = {
  open: boolean;
  budget: Budget;
  onClose: () => void;
  onDone: (budget: Budget) => void;
};

/** Approve a draft budget. The backend refuses a budget with no lines. */
export function ApproveBudgetDialog({ open, budget, onClose, onDone }: ApproveBudgetDialogProps) {
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    try {
      const raw = await approveBudget(budget.id);
      toast.success(`${budget.code} has been approved.`);
      onDone(parseBudgetOrThrow(raw, "approved budget"));
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "We could not approve this budget.");
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title="Approve budget"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button onClick={submit} loading={submitting}>
            Approve budget
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3 text-sm text-muted-foreground">
        <ErrorBanner message={error} />
        <p>
          Approving <span className="font-medium text-foreground">{budget.code}</span> freezes its lines. A budget
          with no lines cannot be approved.
        </p>
        <p>An approved budget can only be changed by raising a new version.</p>
      </div>
    </Dialog>
  );
}

type ReviseBudgetDialogProps = {
  open: boolean;
  budget: Budget;
  onClose: () => void;
  onDone: (budget: Budget) => void;
};

/** Raise a new draft version from an approved budget. */
export function ReviseBudgetDialog({ open, budget, onClose, onDone }: ReviseBudgetDialogProps) {
  const [name, setName] = useState("");
  const [notes, setNotes] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;

    const payload: BudgetRevisePayload = {};
    const trimmedName = name.trim();
    if (trimmedName !== "") payload.name = trimmedName;
    if (notes.trim() !== "") payload.notes = notes.trim();

    const clientErrors = validateBudgetRevise(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setError(null);
    try {
      const raw = await reviseBudget(budget.id, payload);
      toast.success(`A new draft version of ${budget.code} has been created.`);
      onDone(parseBudgetOrThrow(raw, "revised budget"));
    } catch (caught) {
      if (caught instanceof ApiError) {
        setErrors(firstFieldErrors(caught.errors));
        setError(caught.message);
      } else {
        setError("We could not revise this budget.");
      }
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title="Revise budget"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="budget-revise-form" loading={submitting}>
            Create new version
          </Button>
        </>
      }
    >
      <form id="budget-revise-form" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <ErrorBanner message={error} />
        <p className="text-sm text-muted-foreground">
          Raises a new draft version of <span className="font-medium text-foreground">{budget.code}</span>, copying
          its year and lines. The approved version is left untouched.
        </p>

        <FormField
          label="Name"
          htmlFor="budget-revise-name"
          error={errors.name}
          hint="Optional. Leave blank to reuse the current name."
        >
          <Input
            id="budget-revise-name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            invalid={Boolean(errors.name)}
            autoComplete="off"
          />
        </FormField>

        <FormField label="Notes" htmlFor="budget-revise-notes" error={errors.notes}>
          <textarea
            id="budget-revise-notes"
            className={textareaClasses}
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
          />
        </FormField>
      </form>
    </Dialog>
  );
}

type DeleteBudgetDialogProps = {
  open: boolean;
  budget: Budget;
  onClose: () => void;
  onDeleted: () => void;
};

/** Delete a draft budget. The backend refuses once the budget is approved. */
export function DeleteBudgetDialog({ open, budget, onClose, onDeleted }: DeleteBudgetDialogProps) {
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    try {
      await deleteBudget(budget.id);
      toast.success(`${budget.code} has been deleted.`);
      onDeleted();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "We could not delete this budget.");
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title="Delete budget"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button variant="danger" onClick={submit} loading={submitting}>
            Delete budget
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3 text-sm text-muted-foreground">
        <ErrorBanner message={error} />
        <p>
          Delete <span className="font-medium text-foreground">{budget.code}</span> — {budget.name}? This cannot be
          undone. Only a draft budget can be deleted.
        </p>
      </div>
    </Dialog>
  );
}
