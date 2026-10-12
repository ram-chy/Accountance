"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";

import { deleteJournal, postJournal } from "@/lib/journals/api";
import type { Journal } from "@/lib/journals/types";

/**
 * Confirmation for the two consequential journal actions: posting a draft into
 * the accounting record and deleting a draft.
 *
 * Posting is not reversible in this phase — a posted journal is immutable and
 * must be corrected by another journal — so it is confirmed explicitly, and the
 * backend's own refusals (a closed period, a missing period, an inactive account,
 * an already-posted entry) are shown verbatim rather than guessed at.
 */

export type JournalAction =
  | { kind: "post"; journal: Journal }
  | { kind: "delete"; journal: Journal };

type JournalConfirmDialogProps = {
  action: JournalAction | null;
  onClose: () => void;
  onDone: () => Promise<void> | void;
};

function explainError(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function JournalConfirmDialog({ action, onClose, onDone }: JournalConfirmDialogProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (action === null) {
    return null;
  }

  const { kind, journal } = action;

  const title = kind === "post" ? "Post journal" : "Delete draft journal";
  const confirmLabel = kind === "post" ? "Post journal" : "Delete draft";

  const description =
    kind === "post"
      ? `“${journal.journal_number}” will be posted to the accounting record for ${journal.journal_date}.`
      : `“${journal.journal_number}” will be permanently removed.`;

  const warning =
    kind === "post"
      ? "Posting is permanent: a posted journal cannot be edited or deleted. The date must fall in an open accounting period."
      : "This is permanent and cannot be undone.";

  const run = async () => {
    setPending(true);
    setError(null);

    try {
      if (kind === "post") {
        await postJournal(journal.id);
      } else {
        await deleteJournal(journal.id);
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
      description={description}
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
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">{warning}</p>
        {error ? (
          <ErrorState
            title={kind === "post" ? "The journal could not be posted" : "The journal could not be deleted"}
            message={error}
          />
        ) : null}
      </div>
    </Dialog>
  );
}
