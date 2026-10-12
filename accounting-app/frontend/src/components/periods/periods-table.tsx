"use client";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { formatDateOnly } from "@/lib/format/date";

import type { AccountingPeriod } from "@/lib/periods/types";

/**
 * Responsive accounting-period list: a table on medium+ screens and stacked
 * cards below.
 *
 * Columns follow the verified period contract — name, parent financial year,
 * date range and lifecycle status. The status cell also surfaces the derived
 * posting nuance: a period whose own status is OPEN but whose financial year is
 * closed does not accept postings, because `accepts_postings` folds both states
 * together. Action buttons are permission-gated; the backend re-authorises.
 */

type PeriodRowActionsProps = {
  period: AccountingPeriod;
  canUpdate: boolean;
  canReopen: boolean;
  onReview: (period: AccountingPeriod) => void;
  onEdit: (period: AccountingPeriod) => void;
  onReopen: (period: AccountingPeriod) => void;
};

function StatusCell({ period }: { period: AccountingPeriod }) {
  const blockedByYear = period.status === "OPEN" && !period.accepts_postings;

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <Badge variant={period.status === "OPEN" ? "success" : "neutral"}>
        {period.status === "OPEN" ? "Open" : "Closed"}
      </Badge>
      {blockedByYear ? <Badge variant="warning">Year closed</Badge> : null}
    </div>
  );
}

function rangeLabel(period: AccountingPeriod): string {
  return `${formatDateOnly(period.start_date)} – ${formatDateOnly(period.end_date)}`;
}

function PeriodRowActions({
  period,
  canUpdate,
  canReopen,
  onReview,
  onEdit,
  onReopen,
}: PeriodRowActionsProps) {
  return (
    <div className="flex flex-wrap items-center justify-end gap-1.5">
      <Button size="sm" variant="secondary" onClick={() => onReview(period)}>
        Review
      </Button>

      {canUpdate && period.status === "OPEN" ? (
        <Button size="sm" variant="secondary" onClick={() => onEdit(period)}>
          Edit
        </Button>
      ) : null}

      {canReopen && period.status === "CLOSED" ? (
        <Button size="sm" variant="ghost" onClick={() => onReopen(period)}>
          Reopen
        </Button>
      ) : null}
    </div>
  );
}

export type PeriodsTableProps = Omit<PeriodRowActionsProps, "period"> & {
  items: AccountingPeriod[];
};

export function PeriodsTable({
  items,
  canUpdate,
  canReopen,
  onReview,
  onEdit,
  onReopen,
}: PeriodsTableProps) {
  return (
    <>
      <table className="hidden w-full border-collapse text-left md:table">
        <caption className="sr-only">Accounting periods</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Name
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Financial year
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Period
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Status
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              <span className="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((period) => (
            <tr key={period.id} className="border-b border-border last:border-0">
              <td className="px-3 py-2 text-sm font-medium text-foreground">
                {period.name}
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {period.financial_year?.name ?? "—"}
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {rangeLabel(period)}
              </td>
              <td className="px-3 py-2">
                <StatusCell period={period} />
              </td>
              <td className="px-3 py-2">
                <PeriodRowActions
                  period={period}
                  canUpdate={canUpdate}
                  canReopen={canReopen}
                  onReview={onReview}
                  onEdit={onEdit}
                  onReopen={onReopen}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <ul className="flex flex-col gap-3 md:hidden">
        {items.map((period) => (
          <li
            key={period.id}
            className="rounded-lg border border-border bg-surface p-4"
          >
            <div className="flex items-baseline justify-between gap-3">
              <p className="text-sm font-medium text-foreground">{period.name}</p>
              <StatusCell period={period} />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
              {period.financial_year?.name ?? "No financial year"}
            </p>
            <p className="mt-0.5 text-xs text-muted-foreground">{rangeLabel(period)}</p>
            <div className="mt-3 border-t border-border pt-3">
              <PeriodRowActions
                period={period}
                canUpdate={canUpdate}
                canReopen={canReopen}
                onReview={onReview}
                onEdit={onEdit}
                onReopen={onReopen}
              />
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
