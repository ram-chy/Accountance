"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";
import { formatDateOnly } from "@/lib/format/date";

import { closeFinancialYear, generateFinancialYearPeriods } from "@/lib/financial-years/api";
import type { FinancialYear } from "@/lib/financial-years/types";
import { parseGeneratePeriodsResult } from "@/lib/financial-years/validators";

/**
 * Confirmation for the two consequential financial-year actions: generating the
 * year's monthly periods and finalizing the year.
 *
 * Generation is idempotent and additive (existing months are left untouched), so
 * it is described as such. Finalizing is the heavier act — it is refused unless
 * every period is already closed and no critical control is failing — so it is
 * confirmed explicitly and the backend's own refusal is shown verbatim.
 */

export type FinancialYearAction =
  | { kind: "generate"; year: FinancialYear }
  | { kind: "finalize"; year: FinancialYear };

type FinancialYearActionDialogProps = {
  action: FinancialYearAction | null;
  onClose: () => void;
  onDone: () => Promise<void> | void;
};

function explainError(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function FinancialYearActionDialog({
  action,
  onClose,
  onDone,
}: FinancialYearActionDialogProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (action === null) {
    return null;
  }

  const { kind, year } = action;
  const range = `${formatDateOnly(year.start_date)} – ${formatDateOnly(year.end_date)}`;

  const title = kind === "generate" ? "Generate accounting periods" : "Finalize financial year";

  const run = async () => {
    setPending(true);
    setError(null);

    try {
      if (kind === "generate") {
        const raw = await generateFinancialYearPeriods(year.id);
        const result = parseGeneratePeriodsResult(raw);

        toast.success(
          result === null
            ? `Periods generated for ${year.name}.`
            : `${year.name} now has ${result.periods_count} period${result.periods_count === 1 ? "" : "s"}.`,
        );
      } else {
        await closeFinancialYear(year.id);
        toast.success(`${year.name} has been finalized.`);
      }

      await onDone();
      onClose();
    } catch (caught) {
      setError(explainError(caught));
    } finally {
      setPending(false);
    }
  };

  return (
    <Dialog
      open
      title={title}
      description={
        kind === "generate"
          ? `Monthly accounting periods will be created across ${range}.`
          : `“${year.name}” (${range}) will be marked finished.`
      }
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant={kind === "finalize" ? "danger" : "primary"}
            loading={pending}
            onClick={() => void run()}
          >
            {kind === "generate" ? "Generate periods" : "Finalize year"}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {kind === "generate" ? (
          <p className="text-sm text-muted-foreground">
            Generation is safe to run more than once: months that already exist are
            left untouched, and nothing is created outside the year&apos;s own date
            range.
          </p>
        ) : (
          <>
            <p className="text-sm text-muted-foreground">
              Every period in this year must be closed first. Finalizing writes no
              journal entry — retained earnings are already derived from posted
              lines — and is refused while a critical accounting control is failing.
            </p>
            <p className="text-sm text-muted-foreground">
              This is permanent: a closed financial year cannot be reopened.
            </p>
            <div className="flex flex-wrap gap-1.5 text-sm text-muted-foreground">
              <span>
                {year.periods_count} period{year.periods_count === 1 ? "" : "s"}
              </span>
              <span aria-hidden="true">•</span>
              <span>{year.open_periods_count} still open</span>
            </div>
          </>
        )}

        {error ? (
          <ErrorState
            title={kind === "generate" ? "Periods could not be generated" : "The year could not be finalized"}
            message={error}
          />
        ) : null}
      </div>
    </Dialog>
  );
}
