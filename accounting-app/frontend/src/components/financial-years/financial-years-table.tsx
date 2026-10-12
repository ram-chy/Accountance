"use client";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { formatDateOnly } from "@/lib/format/date";

import type { FinancialYear } from "@/lib/financial-years/types";

import type { FinancialYearAction } from "./financial-year-action-dialog";

/**
 * Responsive financial-year list: a table on medium+ screens and stacked cards
 * below.
 *
 * Columns follow the verified year contract — name, date range, lifecycle status
 * and the period counts the backend derives. Action buttons are rendered only
 * when the caller has the matching permission; finalize is additionally disabled
 * until the backend's own `can_close` flag says every period is closed, with the
 * reason shown as a title rather than guessing.
 */

type FinancialYearRowActionsProps = {
  year: FinancialYear;
  canUpdate: boolean;
  canCreate: boolean;
  canClose: boolean;
  onEdit: (year: FinancialYear) => void;
  onAction: (action: FinancialYearAction) => void;
};

function StatusCell({ year }: { year: FinancialYear }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <Badge variant={year.status === "OPEN" ? "success" : "neutral"}>
        {year.status === "OPEN" ? "Open" : "Closed"}
      </Badge>
      {year.status === "OPEN" && year.open_periods_count > 0 ? (
        <Badge variant="warning">{year.open_periods_count} open</Badge>
      ) : null}
    </div>
  );
}

function PeriodsCell({ year }: { year: FinancialYear }) {
  return (
    <span className="text-sm text-foreground">
      {year.periods_count} period{year.periods_count === 1 ? "" : "s"}
    </span>
  );
}

function rangeLabel(year: FinancialYear): string {
  return `${formatDateOnly(year.start_date)} – ${formatDateOnly(year.end_date)}`;
}

function FinancialYearRowActions({
  year,
  canUpdate,
  canCreate,
  canClose,
  onEdit,
  onAction,
}: FinancialYearRowActionsProps) {
  const isOpen = year.status === "OPEN";

  const finalizeDisabled = !year.can_close;
  const finalizeReason = !isOpen
    ? "This financial year is already closed."
    : year.periods_count === 0
      ? "Generate the year's accounting periods before finalizing."
      : year.open_periods_count > 0
        ? `Close all ${year.open_periods_count} open period(s) before finalizing.`
        : "Finalize this financial year";

  return (
    <div className="flex flex-wrap items-center justify-end gap-1.5">
      {canCreate && isOpen ? (
        <Button
          size="sm"
          variant="secondary"
          onClick={() => onAction({ kind: "generate", year })}
        >
          Generate periods
        </Button>
      ) : null}

      {canUpdate && isOpen ? (
        <Button size="sm" variant="secondary" onClick={() => onEdit(year)}>
          Edit
        </Button>
      ) : null}

      {canClose && isOpen ? (
        <Button
          size="sm"
          variant="primary"
          disabled={finalizeDisabled}
          title={finalizeReason}
          onClick={() => onAction({ kind: "finalize", year })}
        >
          Finalize
        </Button>
      ) : null}
    </div>
  );
}

export type FinancialYearsTableProps = Omit<FinancialYearRowActionsProps, "year"> & {
  items: FinancialYear[];
};

export function FinancialYearsTable({
  items,
  canUpdate,
  canCreate,
  canClose,
  onEdit,
  onAction,
}: FinancialYearsTableProps) {
  return (
    <>
      <table className="hidden w-full border-collapse text-left md:table">
        <caption className="sr-only">Financial years</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Name
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Period
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Periods
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
          {items.map((year) => (
            <tr key={year.id} className="border-b border-border last:border-0">
              <td className="px-3 py-2 text-sm font-medium text-foreground">
                {year.name}
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {rangeLabel(year)}
              </td>
              <td className="px-3 py-2">
                <PeriodsCell year={year} />
              </td>
              <td className="px-3 py-2">
                <StatusCell year={year} />
              </td>
              <td className="px-3 py-2">
                <FinancialYearRowActions
                  year={year}
                  canUpdate={canUpdate}
                  canCreate={canCreate}
                  canClose={canClose}
                  onEdit={onEdit}
                  onAction={onAction}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <ul className="flex flex-col gap-3 md:hidden">
        {items.map((year) => (
          <li
            key={year.id}
            className="rounded-lg border border-border bg-surface p-4"
          >
            <div className="flex items-baseline justify-between gap-3">
              <p className="text-sm font-medium text-foreground">{year.name}</p>
              <StatusCell year={year} />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">{rangeLabel(year)}</p>
            <div className="mt-2">
              <PeriodsCell year={year} />
            </div>
            <div className="mt-3 border-t border-border pt-3">
              <FinancialYearRowActions
                year={year}
                canUpdate={canUpdate}
                canCreate={canCreate}
                canClose={canClose}
                onEdit={onEdit}
                onAction={onAction}
              />
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
