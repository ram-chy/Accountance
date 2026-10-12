"use client";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { formatDateOnly } from "@/lib/format/date";
import { MONEY_DISPLAY_DECIMALS, formatMoney } from "@/lib/format/money";
import { cashBankTransactionTypeLabel, type CashBankTransaction } from "@/lib/cash-bank/types";

import type { CashBankTransactionAction } from "./cash-bank-transaction-confirm-dialog";

/**
 * Read-only cash/bank transaction detail.
 *
 * Shows the movement as it was recorded — the two sides, the amount and the
 * audit trail. A posted transaction is final (its journal is shown once it
 * exists), so its only action is Close; a draft exposes Edit/Post/Delete when the
 * caller holds the matching permission. All figures come from the validated row.
 */

function accountLabel(account: { code: string; name: string } | null, fallbackId: number): string {
  if (account === null) {
    return `Account #${fallbackId}`;
  }

  return `${account.code} — ${account.name}`;
}

export function CashBankTransactionDetailDialog({
  transaction,
  canUpdate,
  canDelete,
  canPost,
  onClose,
  onEdit,
  onAction,
}: {
  transaction: CashBankTransaction | null;
  canUpdate: boolean;
  canDelete: boolean;
  canPost: boolean;
  onClose: () => void;
  onEdit: (transaction: CashBankTransaction) => void;
  onAction: (action: CashBankTransactionAction) => void;
}) {
  if (transaction === null) {
    return null;
  }

  const isDraft = transaction.status === "DRAFT";
  const source = transaction.source_account;
  const destination = transaction.destination_account;

  const footer = (
    <>
      <Button variant="secondary" onClick={onClose}>
        Close
      </Button>
      {isDraft && canDelete ? (
        <Button variant="ghost" className="text-danger hover:bg-danger-soft hover:text-danger" onClick={() => onAction({ kind: "delete", transaction })}>
          Delete
        </Button>
      ) : null}
      {isDraft && canUpdate ? (
        <Button variant="secondary" onClick={() => onEdit(transaction)}>
          Edit
        </Button>
      ) : null}
      {isDraft && canPost ? (
        <Button onClick={() => onAction({ kind: "post", transaction })}>Post</Button>
      ) : null}
    </>
  );

  return (
    <Dialog open title={transaction.transaction_number} onClose={onClose} footer={footer}>
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={isDraft ? "warning" : "success"}>
            {isDraft ? "Draft" : "Posted"}
          </Badge>
          <Badge variant="info">{cashBankTransactionTypeLabel(transaction.transaction_type)}</Badge>
        </div>

        <div className="rounded-lg border border-border bg-surface-muted px-4 py-3">
          <p className="text-xs font-medium text-muted-foreground">Amount</p>
          <p className="font-mono text-lg font-semibold text-foreground">
            {formatMoney(transaction.amount, { decimals: MONEY_DISPLAY_DECIMALS })}
          </p>
        </div>

        <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
          <div className="flex flex-col gap-0.5">
            <dt className="text-xs font-medium text-muted-foreground">Date</dt>
            <dd className="text-sm text-foreground">
              {formatDateOnly(transaction.transaction_date)}
            </dd>
          </div>
          <div className="flex flex-col gap-0.5">
            <dt className="text-xs font-medium text-muted-foreground">Reference</dt>
            <dd className="text-sm text-foreground">{transaction.reference ?? "—"}</dd>
          </div>
          <div className="flex flex-col gap-0.5">
            <dt className="text-xs font-medium text-muted-foreground">From</dt>
            <dd className="text-sm text-foreground">
              {accountLabel(source, transaction.source_account_id)}
            </dd>
          </div>
          <div className="flex flex-col gap-0.5">
            <dt className="text-xs font-medium text-muted-foreground">Into</dt>
            <dd className="text-sm text-foreground">
              {accountLabel(destination, transaction.destination_account_id)}
            </dd>
          </div>
          {transaction.status === "POSTED" ? (
            <div className="flex flex-col gap-0.5">
              <dt className="text-xs font-medium text-muted-foreground">Journal</dt>
              <dd className="text-sm text-foreground">
                {transaction.journal_id !== null ? `#${transaction.journal_id}` : "—"}
              </dd>
            </div>
          ) : null}
          <div className="flex flex-col gap-0.5 sm:col-span-2">
            <dt className="text-xs font-medium text-muted-foreground">Notes</dt>
            <dd className="text-sm text-foreground">{transaction.notes ?? "—"}</dd>
          </div>
        </dl>
      </div>
    </Dialog>
  );
}
