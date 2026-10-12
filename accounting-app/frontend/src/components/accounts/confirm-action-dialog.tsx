"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";

import { activateAccount, deactivateAccount, deleteAccount } from "@/lib/accounts/api";
import type { Account } from "@/lib/accounts/types";

/**
 * Confirmation for the consequential account lifecycle actions:
 * activate, deactivate and delete.
 *
 * Each runs while the dialog is open, refuses double submission, and surfaces
 * the backend's restrictions (a system account cannot be deactivated; an
 * account with journal history or children cannot be deleted) as an inline
 * error. The list is refreshed by the view only after the backend confirms.
 */

export type AccountLifecycleAction =
  | { kind: "deactivate"; account: Account }
  | { kind: "activate"; account: Account }
  | { kind: "delete"; account: Account };

type ConfirmActionDialogProps = {
  action: AccountLifecycleAction | null;
  onClose: () => void;
  onDone: () => Promise<void> | void;
};

const KINDS = {
  deactivate: {
    title: "Deactivate account",
    confirmLabel: "Deactivate",
    description: (account: Account) =>
      `“${account.code} ${account.name}” will stop accepting new journal postings. Its history stays readable in reports.`,
    warning: "Deactivating is reversible — you can activate the account again later.",
  },
  activate: {
    title: "Activate account",
    confirmLabel: "Activate",
    description: (account: Account) =>
      `“${account.code} ${account.name}” will be available for journal postings again.`,
    warning: null,
  },
  delete: {
    title: "Delete account",
    confirmLabel: "Delete account",
    description: (account: Account) =>
      `“${account.code} ${account.name}” will be permanently removed.`,
    warning: "This is permanent and cannot be undone.",
  },
} as const;

function explainError(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function ConfirmActionDialog({ action, onClose, onDone }: ConfirmActionDialogProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!action) {
    return null;
  }

  const { kind, account } = action;
  const copy = KINDS[kind];

  const execute = async () => {
    setPending(true);
    setError(null);

    try {
      if (kind === "activate") {
        await activateAccount(account.id);
      } else if (kind === "deactivate") {
        await deactivateAccount(account.id);
      } else {
        await deleteAccount(account.id);
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
            variant={kind === "delete" ? "danger" : "primary"}
            loading={pending}
            onClick={() => void execute()}
          >
            {copy.confirmLabel}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {copy.warning ? (
          <p className="text-sm text-muted-foreground">{copy.warning}</p>
        ) : null}
        {error ? (
          <ErrorState title="The action could not be completed" message={error} />
        ) : null}
      </div>
    </Dialog>
  );
}