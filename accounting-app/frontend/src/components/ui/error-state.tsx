import { cn } from "@/lib/utils/cn";
import type { ReactNode } from "react";

type ErrorStateProps = {
  title?: string;
  message?: string;
  /** Correlation id from the API layer, surfaced for support/debugging. */
  requestId?: string;
  action?: ReactNode;
  className?: string;
};

/** Accessible error surface used by data-loading panels. */
export function ErrorState({
  title = "Something went wrong",
  message = "We could not load this content. Please try again.",
  requestId,
  action,
  className,
}: ErrorStateProps) {
  return (
    <div
      role="alert"
      className={cn(
        "flex flex-col gap-2 rounded-lg border border-danger-border bg-danger-soft px-5 py-4",
        className,
      )}
    >
      <p className="text-sm font-semibold text-danger-soft-foreground">{title}</p>
      <p className="text-sm text-danger-soft-foreground/90">{message}</p>
      {requestId ? (
        <p className="font-mono text-xs text-danger-soft-foreground/80">
          Reference: {requestId}
        </p>
      ) : null}
      {action ? <div className="pt-1">{action}</div> : null}
    </div>
  );
}
