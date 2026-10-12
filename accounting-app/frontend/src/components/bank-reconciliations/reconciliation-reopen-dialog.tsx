"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";
import { formatDateOnly } from "@/lib/format/date";
import { reopenBankReconciliation } from "@/lib/bank-reconciliations/api";
import type { BankReconciliation } from "@/lib/bank-reconciliations/types";

/**
 * Confirmation for reopening a completed reconciliation.
 *
 * Reopening is a larger grant than completing, so it is a separate, explicit act
 * with its own permission. The backend returns the reconciliation to the
 * in-progress state so its movements can be adjusted again; the journal history
 * itself is untouched.
 */

type ReconciliationReopenDialogProps = {
  reconciliation: BankReconciliation | null;
  onClose: () => void;
  onDone: () => Promise<void> | void;
};

function explainError(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function ReconciliationReopenDialog({
  reconciliation,
  onClose,
  onDone,
}: ReconciliationReopenDialogProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (reconciliation === null) {
    return null;
  }

  const range = `${formatDateOnly(reconciliation.from_date)} – ${formatDateOnly(reconciliation.to_date)}`;

  const run = async () => {
    setPending(true);
    setError(null);

    try {
      await reopenBankReconciliation(reconciliation.id);
      toast.success("The reconciliation has been reopened.");
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
      title="Reopen reconciliation"
      description={range}
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" loading={pending} onClick={() => void run()}>
            Reopen reconciliation
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">
          The reconciliation returns to the in-progress state so its cleared movements can be
          adjusted. Postings in the journal are not changed.
        </p>
        <p className="text-sm text-muted-foreground">
          It can be completed again once the statement and ledger agree.
        </p>
        {error ? (
          <ErrorState title="The reconciliation could not be reopened" message={error} />
        ) : null}
      </div>
    </Dialog>
  );
}
