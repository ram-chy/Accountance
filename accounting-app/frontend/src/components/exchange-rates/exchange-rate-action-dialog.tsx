"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";
import { activateExchangeRate, deactivateExchangeRate } from "@/lib/exchange-rates/api";
import type { ExchangeRate } from "@/lib/exchange-rates/types";

/**
 * Confirmation for a rate's lifecycle transition.
 *
 * Activation and deactivation ride on `accounting.exchange_rate.update` (there
 * is no delete — a rate a posted document used must stay readable forever).
 * Withdrawing a rate stops it pricing new documents; it does not remove it from
 * the ones it already priced. The backend owns the rule (an already-active rate
 * cannot be re-activated) and any refusal is surfaced verbatim.
 */

export type ExchangeRateActionKind = "activate" | "deactivate";

type ExchangeRateActionDialogProps = {
  rate: ExchangeRate | null;
  action: ExchangeRateActionKind | null;
  onClose: () => void;
  onDone: () => Promise<void> | void;
};

function label(rate: ExchangeRate): string {
  return rate.pair ?? `#${rate.from_currency_id}/#${rate.to_currency_id}`;
}

const COPY: Record<ExchangeRateActionKind, { title: (l: string) => string; confirm: string; verb: string }> = {
  activate: {
    title: (l) => `Activate ${l}?`,
    confirm: "Activate rate",
    verb: "activated",
  },
  deactivate: {
    title: (l) => `Deactivate ${l}?`,
    confirm: "Deactivate rate",
    verb: "deactivated",
  },
};

const BODY: Record<ExchangeRateActionKind, string> = {
  activate:
    "The rate becomes available to price new documents again. Documents already priced by a different rate are unaffected.",
  deactivate:
    "The rate stops pricing new documents from its effective date. It stays on file so documents it already priced keep their meaning — this is the reversible alternative to deletion.",
};

function explain(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function ExchangeRateActionDialog({
  rate,
  action,
  onClose,
  onDone,
}: ExchangeRateActionDialogProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (rate === null || action === null) {
    return null;
  }

  const copy = COPY[action];
  const pair = label(rate);

  const run = async () => {
    setPending(true);
    setError(null);

    try {
      if (action === "activate") {
        await activateExchangeRate(rate.id);
      } else {
        await deactivateExchangeRate(rate.id);
      }

      toast.success(`${pair} has been ${copy.verb}.`);
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
      title={copy.title(pair)}
      description={`1 ${rate.from_currency ?? `#${rate.from_currency_id}`} = ${rate.rate} ${rate.to_currency ?? `#${rate.to_currency_id}`} · effective ${rate.effective_date}`}
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
