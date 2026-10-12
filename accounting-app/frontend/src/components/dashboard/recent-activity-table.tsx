"use client";

import { Badge, type BadgeVariant } from "@/components/ui/badge";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { formatMoneyValue } from "@/lib/dashboard/format";
import type { CurrencyInfo, JournalActivity, Loadable } from "@/lib/dashboard/types";
import { formatDateOnly } from "@/lib/format/date";

import { TableSkeleton } from "./states";

export type RecentActivityTableProps = {
  state: Loadable<JournalActivity[]>;
  currency: CurrencyInfo | null;
};

function statusVariant(status: string): BadgeVariant {
  switch (status.toUpperCase()) {
    case "POSTED":
      return "success";
    case "DRAFT":
      return "warning";
    case "VOID":
    case "VOIDED":
    case "CANCELLED":
    case "CANCELED":
      return "danger";
    default:
      return "neutral";
  }
}

function sourceLabel(sourceType: string | null): string {
  if (!sourceType) {
    return "Manual";
  }

  return sourceType
    .replace(/[_-]+/g, " ")
    .toLowerCase()
    .replace(/\b\w/g, (character) => character.toUpperCase());
}

/**
 * The most recent posted journal entries for the selected period.
 *
 * Read-only; the full journal module arrives in a later phase. Only the fields
 * the list needs are shown, and status is conveyed by text as well as colour so
 * it does not rely on hue alone.
 */
export function RecentActivityTable({ state, currency }: RecentActivityTableProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Recent activity</CardTitle>
        <CardDescription>Latest posted journals for the selected period.</CardDescription>
      </CardHeader>
      <CardContent className="pt-2">
        {state.status === "loading" ? <TableSkeleton /> : null}

        {state.status === "error" ? (
          <ErrorState
            title="Could not load activity"
            message={state.message}
            requestId={state.requestId}
          />
        ) : null}

        {state.status === "unavailable" ? (
          <p className="text-sm text-muted-foreground">{state.reason}</p>
        ) : null}

        {state.status === "ready" && state.data.length === 0 ? (
          <EmptyState
            title="No posted journals"
            description="Journal activity for the selected period will appear here."
          />
        ) : null}

        {state.status === "ready" && state.data.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[40rem] border-collapse text-sm">
              <caption className="sr-only">
                Recent posted journal entries for the selected period.
              </caption>
              <thead>
                <tr className="border-b border-border text-left text-xs font-medium text-muted-foreground">
                  <th scope="col" className="py-2 pr-4 font-medium">
                    Date
                  </th>
                  <th scope="col" className="py-2 pr-4 font-medium">
                    Reference
                  </th>
                  <th scope="col" className="py-2 pr-4 font-medium">
                    Description
                  </th>
                  <th scope="col" className="py-2 pr-4 font-medium">
                    Type
                  </th>
                  <th scope="col" className="py-2 pr-4 font-medium">
                    Status
                  </th>
                  <th scope="col" className="py-2 text-right font-medium">
                    Amount
                  </th>
                </tr>
              </thead>
              <tbody>
                {state.data.map((entry) => (
                  <tr key={entry.id} className="border-b border-border last:border-0">
                    <td className="whitespace-nowrap py-3 pr-4 text-muted-foreground">
                      {formatDateOnly(entry.date)}
                    </td>
                    <td className="whitespace-nowrap py-3 pr-4 font-medium text-foreground">
                      {entry.number || "—"}
                    </td>
                    <td className="max-w-[16rem] truncate py-3 pr-4 text-foreground">
                      {entry.description || entry.reference || "—"}
                    </td>
                    <td className="whitespace-nowrap py-3 pr-4 text-muted-foreground">
                      {sourceLabel(entry.sourceType)}
                    </td>
                    <td className="whitespace-nowrap py-3 pr-4">
                      <Badge variant={statusVariant(entry.status)}>
                        {entry.status}
                      </Badge>
                    </td>
                    <td className="whitespace-nowrap py-3 text-right tabular-nums text-foreground">
                      {formatMoneyValue(entry.amount, currency)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}
