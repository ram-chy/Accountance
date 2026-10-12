"use client";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { formatDateOnly } from "@/lib/format/date";
import { formatDecimal } from "@/lib/format/money";

import type { TaxRate } from "@/lib/taxes/types";

/**
 * A tax's effective-dated rate history.
 *
 * Each row is one rate period. The rate is a percentage decimal string, rendered
 * through the precision-safe formatter; a null end date means the period is
 * open-ended and is shown as such. Actions are permission-gated and the backend
 * re-authorises every call.
 */

export type TaxRateRowActionsProps = {
  rate: TaxRate;
  canUpdate: boolean;
  canDelete: boolean;
  onEdit: (rate: TaxRate) => void;
  onActivate: (rate: TaxRate) => void;
  onDeactivate: (rate: TaxRate) => void;
  onDelete: (rate: TaxRate) => void;
};

function TaxRateRowActions({
  rate,
  canUpdate,
  canDelete,
  onEdit,
  onActivate,
  onDeactivate,
  onDelete,
}: TaxRateRowActionsProps) {
  return (
    <div className="flex flex-wrap items-center justify-end gap-1.5">
      {canUpdate ? (
        <Button size="sm" variant="secondary" onClick={() => onEdit(rate)}>
          Edit
        </Button>
      ) : null}

      {canUpdate ? (
        rate.is_active ? (
          <Button size="sm" variant="ghost" onClick={() => onDeactivate(rate)}>
            Deactivate
          </Button>
        ) : (
          <Button size="sm" variant="ghost" onClick={() => onActivate(rate)}>
            Activate
          </Button>
        )
      ) : null}

      {canDelete ? (
        <Button size="sm" variant="ghost" onClick={() => onDelete(rate)}>
          Delete
        </Button>
      ) : null}
    </div>
  );
}

export type TaxRatesTableProps = Omit<TaxRateRowActionsProps, "rate"> & {
  items: TaxRate[];
};

function statusCell(rate: TaxRate) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <Badge variant={rate.is_active ? "success" : "neutral"}>
        {rate.is_active ? "Active" : "Inactive"}
      </Badge>
      {rate.is_open_ended ? <Badge variant="info">Open-ended</Badge> : null}
    </div>
  );
}

export function TaxRatesTable({
  items,
  canUpdate,
  canDelete,
  onEdit,
  onActivate,
  onDeactivate,
  onDelete,
}: TaxRatesTableProps) {
  return (
    <>
      <table className="hidden w-full border-collapse text-left md:table">
        <caption className="sr-only">Tax rate history</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Rate
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Effective from
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Effective to
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
          {items.map((rate) => (
            <tr key={rate.id} className="border-b border-border last:border-0">
              <td className="px-3 py-2 text-sm font-medium text-foreground">
                {formatDecimal(rate.rate, { fallback: "—" })}%
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {formatDateOnly(rate.effective_from)}
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {rate.effective_to === null ? "Open-ended" : formatDateOnly(rate.effective_to)}
              </td>
              <td className="px-3 py-2">{statusCell(rate)}</td>
              <td className="px-3 py-2">
                <TaxRateRowActions
                  rate={rate}
                  canUpdate={canUpdate}
                  canDelete={canDelete}
                  onEdit={onEdit}
                  onActivate={onActivate}
                  onDeactivate={onDeactivate}
                  onDelete={onDelete}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <ul className="flex flex-col gap-3 md:hidden">
        {items.map((rate) => (
          <li key={rate.id} className="rounded-lg border border-border bg-surface p-4">
            <div className="flex items-baseline justify-between gap-3">
              <p className="text-sm font-medium text-foreground">
                {formatDecimal(rate.rate, { fallback: "—" })}%
              </p>
              {statusCell(rate)}
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
              {formatDateOnly(rate.effective_from)} –{" "}
              {rate.effective_to === null ? "open-ended" : formatDateOnly(rate.effective_to)}
            </p>
            <div className="mt-3 border-t border-border pt-3">
              <TaxRateRowActions
                rate={rate}
                canUpdate={canUpdate}
                canDelete={canDelete}
                onEdit={onEdit}
                onActivate={onActivate}
                onDeactivate={onDeactivate}
                onDelete={onDelete}
              />
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
