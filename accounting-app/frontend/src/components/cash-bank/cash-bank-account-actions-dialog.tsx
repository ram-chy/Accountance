"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";
import { activateBankDetails, deactivateBankDetails, deleteBankDetails } from "@/lib/cash-bank/api";
import type { CashBankAccount } from "@/lib/cash-bank/types";

/**
 * Confirmation for the three bank-details lifecycle actions.
 *
 * A bank account's details can be activated, deactivated (stopped being
 * selectable for new movements without retiring the account) or removed
 * entirely. The classification is deliberately left alone by all three, matching
 * the backend: turning an account back into an ordinary asset is a separate
 * decision the chart of accounts owns.
 *
 * Each action runs while the dialog is open, refuses double submission, and
 * surfaces the backend's refusal (for example removing details the account still
 * relies on) as an inline error. The list refreshes only after the backend
 * confirms.
 */

export type CashBankBankDetailsAction =
  | { kind: "activate"; account: CashBankAccount }
  | { kind: "deactivate"; account: CashBankAccount }
  | { kind: "remove"; account: CashBankAccount };

const KINDS = {
  activate: {
    title: "Activate bank details",
    confirmLabel: "Activate",
    description: (account: CashBankAccount) =>
      `The bank details for “${account.code} ${account.name}” will be selectable for new movements again.`,
    warning: null,
  },
  deactivate: {
    title: "Deactivate bank details",
    confirmLabel: "Deactivate",
    description: (account: CashBankAccount) =>
      `“${account.code} ${account.name}” will stop offering its bank details for new movements. Existing transactions are unaffected.`,
    warning: "The account itself stays in the chart of accounts; only its bank details are turned off.",
  },
  remove: {
    title: "Remove bank details",
    confirmLabel: "Remove bank details",
    description: (account: CashBankAccount) =>
      `The bank details recorded against “${account.code} ${account.name}” will be permanently removed.`,
    warning: "The account stays classified as a bank account; only the details are removed.",
  },
} as const;

function explainError(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function CashBankAccountActionsDialog({
  action,
  onClose,
  onDone,
}: {
  action: CashBankBankDetailsAction | null;
  onClose: () => void;
  onDone: () => Promise<void> | void;
}) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (action === null) {
    return null;
  }

  const { kind, account } = action;
  const copy = KINDS[kind];

  const execute = async () => {
    setPending(true);
    setError(null);

    try {
      if (kind === "activate") {
        await activateBankDetails(account.id);
      } else if (kind === "deactivate") {
        await deactivateBankDetails(account.id);
      } else {
        await deleteBankDetails(account.id);
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
      title={copy.title}
      description={copy.description(account)}
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant={kind === "remove" ? "danger" : "primary"}
            loading={pending}
            onClick={() => void execute()}
          >
            {copy.confirmLabel}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {copy.warning ? <p className="text-sm text-muted-foreground">{copy.warning}</p> : null}
        {error ? (
          <ErrorState title="The action could not be completed" message={error} />
        ) : null}
      </div>
    </Dialog>
  );
}
