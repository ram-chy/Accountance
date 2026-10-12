"use client";

import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { formatDecimal } from "@/lib/format/money";

import type { Tax, TaxCalculationBasisValue, TaxTypeValue } from "@/lib/taxes/types";

/**
 * Responsive tax list: a table on medium+ screens and stacked cards below.
 *
 * Columns follow the verified `TaxResource` — code, name, the type's side(s),
 * the current rate, and active state. The current rate is a decimal string and is
 * rendered through the precision-safe formatter; it is null when no rate covers
 * today, which is shown as "No rate" rather than zero. Actions are permission-
 * gated and the backend re-authorises every call.
 */

type TaxTypeShort = Record<TaxTypeValue, string>;

const TYPE_SHORT: TaxTypeShort = {
  OUTPUT: "Output",
  INPUT: "Input",
  BOTH: "Both",
};

const BASIS_SHORT: Record<TaxCalculationBasisValue, string> = {
  EXCLUSIVE: "Exclusive",
  INCLUSIVE: "Inclusive",
};

function StatusCell({ tax }: { tax: Tax }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <Badge variant={tax.is_active ? "success" : "neutral"}>
        {tax.is_active ? "Active" : "Inactive"}
      </Badge>
      {tax.current_rate === null ? <Badge variant="warning">No current rate</Badge> : null}
    </div>
  );
}

function sideLabel(tax: Tax): string {
  if (tax.applies_to_sales && tax.applies_to_purchase) {
    return "Sales & purchases";
  }

  return tax.applies_to_sales ? "Sales" : "Purchases";
}

export type TaxRowActionsProps = {
  tax: Tax;
  canUpdate: boolean;
  canDelete: boolean;
  onEdit: (tax: Tax) => void;
  onActivate: (tax: Tax) => void;
  onDeactivate: (tax: Tax) => void;
  onDelete: (tax: Tax) => void;
};

function TaxRowActions({
  tax,
  canUpdate,
  canDelete,
  onEdit,
  onActivate,
  onDeactivate,
  onDelete,
}: TaxRowActionsProps) {
  return (
    <div className="flex flex-wrap items-center justify-end gap-1.5">
      <Link
        href={`/taxes/${tax.id}`}
        className="inline-flex h-8 items-center justify-center rounded-md border border-border bg-surface px-3 text-sm font-medium text-foreground transition-colors hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        Manage
      </Link>

      {canUpdate ? (
        <Button size="sm" variant="secondary" onClick={() => onEdit(tax)}>
          Edit
        </Button>
      ) : null}

      {canUpdate ? (
        tax.is_active ? (
          <Button size="sm" variant="ghost" onClick={() => onDeactivate(tax)}>
            Deactivate
          </Button>
        ) : (
          <Button size="sm" variant="ghost" onClick={() => onActivate(tax)}>
            Activate
          </Button>
        )
      ) : null}

      {canDelete ? (
        <Button size="sm" variant="ghost" onClick={() => onDelete(tax)}>
          Delete
        </Button>
      ) : null}
    </div>
  );
}

export type TaxesTableProps = Omit<TaxRowActionsProps, "tax"> & {
  items: Tax[];
};

export function TaxesTable({
  items,
  canUpdate,
  canDelete,
  onEdit,
  onActivate,
  onDeactivate,
  onDelete,
}: TaxesTableProps) {
  return (
    <>
      <table className="hidden w-full border-collapse text-left md:table">
        <caption className="sr-only">Taxes</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Code
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Name
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Applies to
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Current rate
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
          {items.map((tax) => (
            <tr key={tax.id} className="border-b border-border last:border-0">
              <td className="px-3 py-2 text-sm font-medium text-foreground">{tax.code}</td>
              <td className="px-3 py-2 text-sm text-foreground">
                <span className="font-medium">{tax.name}</span>
                <span className="block text-xs text-muted-foreground">
                  {TYPE_SHORT[tax.tax_type]} · {BASIS_SHORT[tax.calculation_basis]}
                </span>
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">{sideLabel(tax)}</td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {tax.current_rate === null
                  ? "No rate"
                  : `${formatDecimal(tax.current_rate, { fallback: "—" })}%`}
              </td>
              <td className="px-3 py-2">
                <StatusCell tax={tax} />
              </td>
              <td className="px-3 py-2">
                <TaxRowActions
                  tax={tax}
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
        {items.map((tax) => (
          <li key={tax.id} className="rounded-lg border border-border bg-surface p-4">
            <div className="flex items-baseline justify-between gap-3">
              <p className="text-sm font-medium text-foreground">
                {tax.code} · {tax.name}
              </p>
              <StatusCell tax={tax} />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
              {TYPE_SHORT[tax.tax_type]} · {BASIS_SHORT[tax.calculation_basis]} ·{" "}
              {sideLabel(tax)}
            </p>
            <p className="mt-0.5 text-xs text-muted-foreground">
              Current rate:{" "}
              {tax.current_rate === null
                ? "No rate"
                : `${formatDecimal(tax.current_rate, { fallback: "—" })}%`}
            </p>
            <div className="mt-3 border-t border-border pt-3">
              <TaxRowActions
                tax={tax}
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
