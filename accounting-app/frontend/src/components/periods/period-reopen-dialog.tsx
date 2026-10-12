"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";
import { formatDateOnly } from "@/lib/format/date";

import { reopenAccountingPeriod } from "@/lib/periods/api";
import type { AccountingPeriod } from "@/lib/periods/types";

/**
 * Confirmation for reopening a closed accounting period.
 *
 * Reopening is a larger grant than closing, so it is a separate, explicit act
 * with its own permission. It changes only whether new postings are accepted for
 * the period's dates — the journal history posted before the close is untouched —
 * and the backend refuses it when the parent financial year is already closed.
 */

type PeriodReopenDialogProps = {
  period: AccountingPeriod | null;
  onClose: () => void;
  onDone: () => Promise<void> | void;
};

function explainError(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function PeriodReopenDialog({ period, onClose, onDone }: PeriodReopenDialogProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (period === null) {
    return null;
  }

  const range = `${formatDateOnly(period.start_date)} – ${formatDateOnly(period.end_date)}`;

  const run = async () => {
    setPending(true);
    setError(null);

    try {
      await reopenAccountingPeriod(period.id);
      toast.success(`${period.name} has been reopened.`);
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
      title={`Reopen ${period.name}`}
      description={range}
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" loading={pending} onClick={() => void run()}>
            Reopen period
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">
          New postings will be accepted for these dates again. Entries posted before the
          period was closed are not changed.
        </p>
        <p className="text-sm text-muted-foreground">
          A period inside a closed financial year cannot be reopened until that year is
          reopened.
        </p>
        {error ? (
          <ErrorState title="The period could not be reopened" message={error} />
        ) : null}
      </div>
    </Dialog>
  );
}
