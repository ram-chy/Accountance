"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { LoadingState } from "@/components/ui/loading-state";
import { ApiError } from "@/lib/api/errors";
import { formatDateOnly } from "@/lib/format/date";

import { closeAccountingPeriod } from "@/lib/periods/api";
import type { AccountingPeriod, ControlFinding } from "@/lib/periods/types";
import { useClosingCheck } from "@/lib/periods/use-closing-check";

/**
 * The period-end review, and the close it authorises.
 *
 * Opening the dialog runs the read-only closing check (`GET .../closing-check`)
 * and renders the verdict: whether the period may be closed, the blocking and
 * warning findings behind that answer, and the backend's own required action.
 * Closing is offered only when the report says `eligible`, and is confirmed in a
 * second step because it changes what posting the period's dates will accept.
 * When the backend refuses a close, its message is shown and the review is
 * re-fetched so the report reflects the state the backend just judged.
 */

type ClosingCheckDialogProps = {
  period: AccountingPeriod | null;
  canClose: boolean;
  onClose: () => void;
  onDone: () => Promise<void> | void;
};

function severityBadge(status: ControlFinding["status"]) {
  if (status === "FAIL") {
    return <Badge variant="danger">Fail</Badge>;
  }

  if (status === "WARNING") {
    return <Badge variant="warning">Warning</Badge>;
  }

  return <Badge variant="success">Pass</Badge>;
}

function FindingList({ title, findings }: { title: string; findings: ControlFinding[] }) {
  if (findings.length === 0) {
    return null;
  }

  return (
    <div className="flex flex-col gap-2">
      <p className="text-sm font-medium text-foreground">{title}</p>
      <ul className="flex flex-col gap-2">
        {findings.map((finding, index) => (
          <li
            key={`${finding.control_code}-${finding.resource_id ?? index}`}
            className="rounded-md border border-border bg-surface p-2.5"
          >
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-mono text-xs text-muted-foreground">
                {finding.control_code}
              </span>
              {severityBadge(finding.status)}
            </div>
            <p className="mt-1 text-sm text-foreground">{finding.description}</p>
            {finding.financial_date || finding.resource_type ? (
              <p className="mt-1 text-xs text-muted-foreground">
                {finding.financial_date ? formatDateOnly(finding.financial_date) : null}
                {finding.financial_date && finding.resource_type ? " · " : null}
                {finding.resource_type
                  ? `${finding.resource_type}${finding.resource_id != null ? ` #${finding.resource_id}` : ""}`
                  : null}
              </p>
            ) : null}
          </li>
        ))}
      </ul>
    </div>
  );
}

function explainError(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }

  return "Something went wrong. Please try again.";
}

export function ClosingCheckDialog({
  period,
  canClose,
  onClose,
  onDone,
}: ClosingCheckDialogProps) {
  const [reloadToken, setReloadToken] = useState(0);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);

  const state = useClosingCheck(period?.id ?? null, period !== null, reloadToken);

  if (period === null) {
    return null;
  }

  const range = `${formatDateOnly(period.start_date)} – ${formatDateOnly(period.end_date)}`;

  const review = state.status === "ready" ? state.data : null;
  const canCloseNow = canClose && review !== null && review.eligible && !review.already_closed;

  const runClose = async () => {
    setPending(true);
    setError(null);

    try {
      await closeAccountingPeriod(period.id);
      toast.success(`${period.name} has been closed.`);
      await onDone();
      onClose();
    } catch (caught) {
      setError(explainError(caught));
      setConfirming(false);
      setReloadToken((token) => token + 1);
    } finally {
      setPending(false);
    }
  };

  const footer =
    confirming && canCloseNow ? (
      <>
        <Button variant="secondary" disabled={pending} onClick={() => setConfirming(false)}>
          Back
        </Button>
        <Button variant="danger" loading={pending} onClick={() => void runClose()}>
          Confirm close period
        </Button>
      </>
    ) : (
      <>
        <Button variant="secondary" disabled={pending} onClick={onClose}>
          Close
        </Button>
        {canCloseNow ? (
          <Button variant="primary" disabled={pending} onClick={() => setConfirming(true)}>
            Close period
          </Button>
        ) : null}
      </>
    );

  return (
    <Dialog
      open
      title={`Closing review: ${period.name}`}
      description={range}
      onClose={pending ? () => undefined : onClose}
      footer={footer}
    >
      <div className="flex flex-col gap-4">
        {state.status === "loading" || state.status === "idle" ? (
          <LoadingState label="Evaluating the period closing review…" />
        ) : null}

        {state.status === "error" ? (
          <ErrorState
            title="Could not evaluate the closing review"
            message={state.message}
            requestId={state.requestId}
            action={
              <Button variant="secondary" onClick={() => setReloadToken((token) => token + 1)}>
                Try again
              </Button>
            }
          />
        ) : null}

        {review ? (
          <>
            <div className="flex flex-wrap items-center gap-2">
              {review.already_closed ? (
                <Badge variant="neutral">Already closed</Badge>
              ) : review.eligible ? (
                <Badge variant="success">Ready to close</Badge>
              ) : (
                <Badge variant="danger">Not ready to close</Badge>
              )}
              <span aria-hidden="true" className="text-border-strong">•</span>
              <Badge variant="success">{review.summary.PASS} pass</Badge>
              <Badge variant="warning">{review.summary.WARNING} warning</Badge>
              <Badge variant="danger">{review.summary.FAIL} fail</Badge>
            </div>

            {review.required_action ? (
              <p className="text-sm text-foreground">{review.required_action}</p>
            ) : (
              <p className="text-sm text-muted-foreground">
                No blocking findings. Closing will stop new postings for these dates.
              </p>
            )}

            <FindingList title="Blocking findings" findings={review.blocking_findings} />
            <FindingList title="Warnings" findings={review.warning_findings} />

            {review.blocking_findings.length === 0 && review.warning_findings.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                The review reported no findings for this period.
              </p>
            ) : null}
          </>
        ) : null}

        {confirming && canCloseNow ? (
          <div className="rounded-md border border-danger-border bg-danger-soft px-3 py-2">
            <p className="text-sm text-danger-soft-foreground">
              Confirm closing “{period.name}”. New postings for its dates will be refused
              until it is reopened.
            </p>
          </div>
        ) : null}

        {error ? (
          <ErrorState title="The period could not be closed" message={error} />
        ) : null}
      </div>
    </Dialog>
  );
}
