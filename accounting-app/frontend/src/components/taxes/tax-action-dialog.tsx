"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";

import { activateTax, deactivateTax, deleteTax } from "@/lib/taxes/api";
import type { Tax } from "@/lib/taxes/types";

/**
 * Confirmation for a tax's lifecycle or destructive action.
 *
 * Activate, deactivate and delete are each confirmed before they run, because all
 * three change what the company charges going forward. The action is driven by the
 * backend: a delete the backend refuses (the tax is referenced by a posted
 * document or by future-reachable rates) surfaces that message verbatim rather
 * than being pre-empted by a client-side rule.
 *
 * The pending state disables both buttons, so a slow request cannot be
 * double-submitted. State is only ever changed by a successful response.
 */

export type TaxActionKind = "activate" | "deactivate" | "delete";

type TaxActionDialogProps = {
  tax: Tax | null;
  action: TaxActionKind | null;
  onClose: () => void;
  onDone: () => Promise<void> | void;
};

const COPY: Record<TaxActionKind, { title: (t: Tax) => string; confirm: string; verb: string }> = {
  activate: {
    title: (tax) => `Activate ${tax.code}?`,
    confirm: "Activate tax",
    verb: "activated",
  },
  deactivate: {
    title: (tax) => `Deactivate ${tax.code}?`,
    confirm: "Deactivate tax",
    verb: "deactivated",
  },
  delete: {
    title: (tax) => `Delete ${tax.code}?`,
    confirm: "Delete tax",
    verb: "deleted",
  },
};

function explain(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function TaxActionDialog({ tax, action, onClose, onDone }: TaxActionDialogProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (tax === null || action === null) {
    return null;
  }

  const copy = COPY[action];

  const run = async () => {
    setPending(true);
    setError(null);

    try {
      if (action === "activate") {
        await activateTax(tax.id);
      } else if (action === "deactivate") {
        await deactivateTax(tax.id);
      } else {
        await deleteTax(tax.id);
      }

      toast.success(`${tax.name} has been ${copy.verb}.`);
      await onDone();
      onClose();
    } catch (caught) {
      setError(explain(caught));
    } finally {
      setPending(false);
    }
  };

  const body: Record<TaxActionKind, string> = {
    activate:
      "New documents may charge this tax again. Documents posted while it was inactive keep the amount they were given.",
    deactivate:
      "New documents will no longer charge this tax. It stays on file so past documents keep their explanation.",
    delete:
      "Deletion is permanent. A tax referenced by a posted document or by a reachable rate cannot be deleted — deactivate it instead.",
  };

  return (
    <Dialog
      open
      title={copy.title(tax)}
      description={tax.name}
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant={action === "delete" ? "danger" : "primary"}
            loading={pending}
            onClick={() => void run()}
          >
            {copy.confirm}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">{body[action]}</p>
        {error ? <ErrorState title="The action could not be completed" message={error} /> : null}
      </div>
    </Dialog>
  );
}
