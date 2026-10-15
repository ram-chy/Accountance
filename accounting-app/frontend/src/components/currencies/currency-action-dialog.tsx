"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";
import { activateCurrency, deactivateCurrency } from "@/lib/currencies/api";
import type { Currency } from "@/lib/currencies/types";

/**
 * Confirmation for a currency's lifecycle transition.
 *
 * There is no delete: a currency a posted document or a rate referenced must
 * stay readable forever, so the lifecycle ends at deactivation. Both
 * transitions are confirmed because deactivating a currency removes it from
 * every company's pickers at once — a global act.
 *
 * The backend owns the rule (an already-active currency cannot be re-activated)
 * and any refusal is surfaced verbatim. State is only changed by a successful
 * response, and the pending flag disables both buttons so a slow request cannot
 * be double-submitted.
 */

export type CurrencyActionKind = "activate" | "deactivate";

type CurrencyActionDialogProps = {
  currency: Currency | null;
  action: CurrencyActionKind | null;
  onClose: () => void;
  onDone: () => Promise<void> | void;
};

const COPY: Record<CurrencyActionKind, { title: (c: Currency) => string; confirm: string; verb: string }> = {
  activate: {
    title: (currency) => `Activate ${currency.code}?`,
    confirm: "Activate currency",
    verb: "activated",
  },
  deactivate: {
    title: (currency) => `Deactivate ${currency.code}?`,
    confirm: "Deactivate currency",
    verb: "deactivated",
  },
};

const BODY: Record<CurrencyActionKind, string> = {
  activate:
    "The currency becomes selectable again for every company. Documents and rates that referenced it are unaffected.",
  deactivate:
    "The currency stops being selectable in pickers for every company and no new rate may reference it. It stays on file so past documents keep their meaning — this is the reversible alternative to deletion.",
};

function explain(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function CurrencyActionDialog({
  currency,
  action,
  onClose,
  onDone,
}: CurrencyActionDialogProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (currency === null || action === null) {
    return null;
  }

  const copy = COPY[action];

  const run = async () => {
    setPending(true);
    setError(null);

    try {
      if (action === "activate") {
        await activateCurrency(currency.id);
      } else {
        await deactivateCurrency(currency.id);
      }

      toast.success(`${currency.code} has been ${copy.verb}.`);
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
      title={copy.title(currency)}
      description={currency.name}
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button loading={pending} onClick={() => void run()}>
            {copy.confirm}
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
