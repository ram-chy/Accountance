"use client";

import { Badge, type BadgeVariant } from "@/components/ui/badge";
import { formatMoneyValue, hasCurrency } from "@/lib/dashboard/format";
import type { CurrencyInfo, Loadable } from "@/lib/dashboard/types";
import { cn } from "@/lib/utils/cn";

import { SummaryValueSkeleton } from "./states";

export type SummaryTone = "neutral" | "negative";

export type FinancialSummaryCardProps = {
  label: string;
  /** Short context line shown under the value (period, as-of date, ...). */
  hint?: string;
  state: Loadable<string>;
  currency: CurrencyInfo | null;
  tone?: SummaryTone;
  badge?: { label: string; variant: BadgeVariant };
};

/**
 * A single financial metric.
 *
 * Handles the four states a dashboard metric can be in — loading, ready, error
 * and unavailable (no permission or unsupported) — so callers only wire data.
 * The amount is formatted with the shared precision-safe money utility and the
 * company currency; a missing currency is shown as a plain number rather than a
 * guessed symbol.
 */
export function FinancialSummaryCard({
  label,
  hint,
  state,
  currency,
  tone = "neutral",
  badge,
}: FinancialSummaryCardProps) {
  return (
    <div
      role="group"
      aria-label={label}
      className="flex flex-col gap-3 rounded-xl border border-border bg-surface p-5"
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-sm font-medium text-muted-foreground">{label}</p>
        {badge && state.status === "ready" ? (
          <Badge variant={badge.variant}>{badge.label}</Badge>
        ) : null}
      </div>

      <div className="flex min-h-7 flex-col gap-1">
        {state.status === "loading" ? <SummaryValueSkeleton /> : null}

        {state.status === "ready" ? (
          <>
            <p
              className={cn(
                "text-2xl font-semibold tabular-nums",
                tone === "negative"
                  ? "text-danger-soft-foreground"
                  : "text-foreground",
              )}
            >
              {formatMoneyValue(state.data, currency)}
            </p>
            {hasCurrency(currency) ? null : (
              <p className="text-xs text-muted-foreground">
                Company currency not configured
              </p>
            )}
          </>
        ) : null}

        {state.status === "error" ? (
          <p role="alert" className="text-sm text-danger-soft-foreground">
            {state.message}
          </p>
        ) : null}

        {state.status === "unavailable" ? (
          <p className="text-sm text-muted-foreground">{state.reason}</p>
        ) : null}
      </div>

      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  );
}
