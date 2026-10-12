"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";
import { MONEY_DISPLAY_DECIMALS, formatMoney } from "@/lib/format/money";
import { deleteCashBankTransaction, postCashBankTransaction } from "@/lib/cash-bank/api";
import type { CashBankTransaction } from "@/lib/cash-bank/types";

/**
 * Confirmation for the two irreversible transaction actions: posting and
 * deleting a draft.
 *
 * Posting writes the movement into the ledger, where it can no longer be edited;
 * deleting a draft removes it for good. Both are the backend's decisions to
 * refuse (a closed period, an ineligible account), so the refusal is surfaced
 * verbatim. A draft is the only thing either action applies to, which is why the
 * buttons that open this dialog are shown only for drafts.
 */

export type CashBankTransactionAction =
  | { kind: "post"; transaction: CashBankTransaction }
  | { kind: "delete"; transaction: CashBankTransaction };

function explainError(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function CashBankTransactionConfirmDialog({
  action,
  onClose,
  onDone,
}: {
  action: CashBankTransactionAction | null;
  onClose: () => void;
  onDone: () => Promise<void> | void;
}) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (action === null) {
    return null;
  }

  const { kind, transaction } = action;
  const amount = formatMoney(transaction.amount, { decimals: MONEY_DISPLAY_DECIMALS });

  const run = async () => {
    setPending(true);
    setError(null);

    try {
      if (kind === "post") {
        await postCashBankTransaction(transaction.id);
      } else {
        await deleteCashBankTransaction(transaction.id);
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
      title={kind === "post" ? "Post cash/bank transaction" : "Delete draft transaction"}
      description={
        kind === "post"
          ? `${transaction.transaction_number} — ${amount} will be posted to the accounting record.`
          : `${transaction.transaction_number} will be permanently removed.`
      }
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant={kind === "delete" ? "danger" : "primary"}
            loading={pending}
            onClick={() => void run()}
          >
            {kind === "post" ? "Post transaction" : "Delete draft"}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">
          {kind === "post"
            ? "Posting is permanent: a posted transaction cannot be edited or deleted. The date must fall in an open accounting period."
            : "This is permanent and cannot be undone."}
        </p>
        {error ? (
          <ErrorState title="The action could not be completed" message={error} />
        ) : null}
      </div>
    </Dialog>
  );
}
