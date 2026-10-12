"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";
import { formatDateOnly } from "@/lib/format/date";
import { MONEY_DISPLAY_DECIMALS, formatMoney } from "@/lib/format/money";
import { completeBankReconciliation } from "@/lib/bank-reconciliations/api";
import type { BankReconciliation } from "@/lib/bank-reconciliations/types";
import { isZeroDecimalString } from "@/lib/bank-reconciliations/validators";

/**
 * Confirmation for completing a bank reconciliation.
 *
 * Completion is a deliberate, explicit act: once reconciled the record is
 * immutable and its movements are settled. The dialog restates the statement
 * closing balance, the backend's reconciled balance and the difference.
 *
 * When the difference is non-zero a warning is shown, but completion is still
 * offered: whether a reconciliation may be completed is the backend's decision
 * (`BankReconciliationCompletionService` refuses a non-zero difference with a
 * 422), so the frontend surfaces that authority rather than reimplementing the
 * rule. A refusal is shown verbatim.
 */

type ReconciliationCompleteDialogProps = {
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

export function ReconciliationCompleteDialog({
  reconciliation,
  onClose,
  onDone,
}: ReconciliationCompleteDialogProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (reconciliation === null) {
    return null;
  }

  const differenceIsZero = isZeroDecimalString(reconciliation.difference);
  const range = `${formatDateOnly(reconciliation.from_date)} – ${formatDateOnly(reconciliation.to_date)}`;

  const run = async () => {
    setPending(true);
    setError(null);

    try {
      await completeBankReconciliation(reconciliation.id);
      toast.success("The reconciliation has been completed.");
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
      title="Complete reconciliation"
      description={range}
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" loading={pending} onClick={() => void run()}>
            Complete reconciliation
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <dl className="flex flex-col divide-y divide-border rounded-lg border border-border bg-surface-muted px-4 py-1">
          <div className="flex items-baseline justify-between gap-4 py-2">
            <dt className="text-sm text-muted-foreground">Statement closing</dt>
            <dd className="font-mono text-sm text-foreground">
              {formatMoney(reconciliation.statement_closing_balance, {
                decimals: MONEY_DISPLAY_DECIMALS,
              })}
            </dd>
          </div>
          <div className="flex items-baseline justify-between gap-4 py-2">
            <dt className="text-sm text-muted-foreground">Reconciled balance</dt>
            <dd className="font-mono text-sm text-foreground">
              {formatMoney(reconciliation.reconciled_balance, {
                decimals: MONEY_DISPLAY_DECIMALS,
              })}
            </dd>
          </div>
          <div className="flex items-baseline justify-between gap-4 py-2">
            <dt className="text-sm text-muted-foreground">Difference</dt>
            <dd className="font-mono text-sm text-foreground">
              {formatMoney(reconciliation.difference, { decimals: MONEY_DISPLAY_DECIMALS })}
            </dd>
          </div>
        </dl>

        {differenceIsZero ? (
          <p className="text-sm text-muted-foreground">
            Once completed, this reconciliation is settled and immutable. It can be reopened
            later if needed.
          </p>
        ) : (
          <div className="rounded-md border border-warning-border bg-warning-soft px-3 py-2">
            <p className="text-sm text-warning-soft-foreground">
              The statement and ledger do not agree yet. Clear the remaining movements, or
              adjust the statement balance, before completing — the server will refuse a
              reconciliation with a non-zero difference.
            </p>
          </div>
        )}

        {error ? (
          <ErrorState title="The reconciliation could not be completed" message={error} />
        ) : null}
      </div>
    </Dialog>
  );
}
