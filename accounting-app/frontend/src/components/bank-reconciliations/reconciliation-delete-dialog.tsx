"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";
import { formatDateOnly } from "@/lib/format/date";
import { deleteBankReconciliation } from "@/lib/bank-reconciliations/api";
import type { BankReconciliation } from "@/lib/bank-reconciliations/types";

/**
 * Confirmation for deleting a reconciliation.
 *
 * Only draft reconciliations are deletable; once in progress or reconciled the
 * backend refuses (the delete route authorises with the update permission). The
 * caller only offers this action for a draft.
 */

type ReconciliationDeleteDialogProps = {
  reconciliation: BankReconciliation | null;
  onClose: () => void;
  onDeleted: () => Promise<void> | void;
};

function explainError(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function ReconciliationDeleteDialog({
  reconciliation,
  onClose,
  onDeleted,
}: ReconciliationDeleteDialogProps) {
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
      await deleteBankReconciliation(reconciliation.id);
      toast.success("The reconciliation has been deleted.");
      await onDeleted();
    } catch (caught) {
      setError(explainError(caught));
      setPending(false);
    }
  };

  return (
    <Dialog
      open
      title="Delete reconciliation"
      description={range}
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button variant="danger" loading={pending} onClick={() => void run()}>
            Delete reconciliation
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">
          This permanently removes the draft reconciliation and any movements cleared into it.
          The underlying journals are not changed.
        </p>
        {error ? (
          <ErrorState title="The reconciliation could not be deleted" message={error} />
        ) : null}
      </div>
    </Dialog>
  );
}
