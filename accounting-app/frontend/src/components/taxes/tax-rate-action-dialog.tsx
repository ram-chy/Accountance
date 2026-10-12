"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";
import { formatDateOnly } from "@/lib/format/date";

import { activateTaxRate, deactivateTaxRate, deleteTaxRate } from "@/lib/taxes/api";
import type { TaxRate } from "@/lib/taxes/types";

/**
 * Confirmation for a tax-rate lifecycle or destructive action.
 *
 * Deactivating a rate retires one period without deleting it, so the
 * effective-dated history still explains which rate was in force on a given day.
 * Deleting is refused by the backend when a posted document used the rate — that
 * message is shown verbatim.
 */

export type RateActionKind = "activate" | "deactivate" | "delete";

type TaxRateActionDialogProps = {
  taxId: number | null;
  rate: TaxRate | null;
  action: RateActionKind | null;
  onClose: () => void;
  onDone: () => Promise<void> | void;
};

const CONFIRM: Record<RateActionKind, string> = {
  activate: "Activate rate",
  deactivate: "Deactivate rate",
  delete: "Delete rate",
};

const VERB: Record<RateActionKind, string> = {
  activate: "activated",
  deactivate: "deactivated",
  delete: "deleted",
};

const BODY: Record<RateActionKind, string> = {
  activate: "This rate period will be used again when resolving the rate for its dates.",
  deactivate:
    "This rate period will be skipped when resolving the rate for its dates. It stays on file for the history.",
  delete:
    "Deletion is permanent. A rate a posted document used cannot be deleted — deactivate it instead.",
};

function explain(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function TaxRateActionDialog({
  taxId,
  rate,
  action,
  onClose,
  onDone,
}: TaxRateActionDialogProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (taxId === null || rate === null || action === null) {
    return null;
  }

  const period = `${formatDateOnly(rate.effective_from)} – ${
    rate.effective_to === null ? "open-ended" : formatDateOnly(rate.effective_to)
  }`;

  const run = async () => {
    setPending(true);
    setError(null);

    try {
      if (action === "activate") {
        await activateTaxRate(taxId, rate.id);
      } else if (action === "deactivate") {
        await deactivateTaxRate(taxId, rate.id);
      } else {
        await deleteTaxRate(taxId, rate.id);
      }

      toast.success(`The ${rate.rate}% rate has been ${VERB[action]}.`);
      await onDone();
      onClose();
    } catch (caught) {
      setError(explain(caught));
    } finally {
      setPending(false);
    }
  };

  return (
    <Dialog
      open
      title={`${CONFIRM[action]} (${rate.rate}%)`}
      description={period}
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
            {CONFIRM[action]}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">{BODY[action]}</p>
        {error ? <ErrorState title="The action could not be completed" message={error} /> : null}
      </div>
    </Dialog>
  );
}
