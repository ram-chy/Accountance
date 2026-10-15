"use client";

import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import type { ExchangeRate } from "@/lib/exchange-rates/types";
import { formatDateOnly } from "@/lib/format/date";

/**
 * Responsive exchange-rate list: a table on medium+ screens and stacked cards
 * below.
 *
 * The direction is spelled out — "1 USD = 83.5000000000 INR" — rather than shown
 * as a bare pair, because the whole contract rests on "units of the to currency
 * per one unit of the from currency" and an ambiguous direction is worse than no
 * rate at all. The rate is a decimal string and is rendered verbatim.
 */

function PairCell({ rate }: { rate: ExchangeRate }) {
  const from = rate.from_currency ?? `#${rate.from_currency_id}`;
  const to = rate.to_currency ?? `#${rate.to_currency_id}`;

  return (
    <>
      <span className="font-medium text-foreground">
        {rate.pair ?? `${from}/${to}`}
      </span>
      <span className="block text-xs text-muted-foreground">
        1 {from} = {rate.rate} {to}
      </span>
    </>
  );
}

function StatusCell({ rate }: { rate: ExchangeRate }) {
  return (
    <Badge variant={rate.is_active ? "success" : "neutral"}>
      {rate.is_active ? "Active" : "Inactive"}
    </Badge>
  );
}

export type ExchangeRateRowActionsProps = {
  rate: ExchangeRate;
  canUpdate: boolean;
  onEdit: (rate: ExchangeRate) => void;
  onActivate: (rate: ExchangeRate) => void;
  onDeactivate: (rate: ExchangeRate) => void;
};

function ExchangeRateRowActions({
  rate,
  canUpdate,
  onEdit,
  onActivate,
  onDeactivate,
}: ExchangeRateRowActionsProps) {
  return (
    <div className="flex flex-wrap items-center justify-end gap-1.5">
      <Link
        href={`/exchange-rates/${rate.id}`}
        className="inline-flex h-8 items-center justify-center rounded-md border border-border bg-surface px-3 text-sm font-medium text-foreground transition-colors hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        View
      </Link>

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
    </div>
  );
}

export type ExchangeRatesTableProps = Omit<ExchangeRateRowActionsProps, "rate"> & {
  items: ExchangeRate[];
};

export function ExchangeRatesTable({
  items,
  canUpdate,
  onEdit,
  onActivate,
  onDeactivate,
}: ExchangeRatesTableProps) {
  const actions = { canUpdate, onEdit, onActivate, onDeactivate };

  return (
    <>
      <table className="hidden w-full border-collapse text-left md:table">
        <caption className="sr-only">Exchange rates</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Pair
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Effective date
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Source
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
              <td className="px-3 py-2 text-sm">
                <PairCell rate={rate} />
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {formatDateOnly(rate.effective_date)}
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">{rate.source ?? "—"}</td>
              <td className="px-3 py-2">
                <StatusCell rate={rate} />
              </td>
              <td className="px-3 py-2">
                <ExchangeRateRowActions rate={rate} {...actions} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <ul className="flex flex-col gap-3 md:hidden">
        {items.map((rate) => (
          <li key={rate.id} className="rounded-lg border border-border bg-surface p-4">
            <div className="flex items-baseline justify-between gap-3">
              <PairCell rate={rate} />
              <StatusCell rate={rate} />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
              Effective {formatDateOnly(rate.effective_date)}
              {rate.source ? ` · ${rate.source}` : ""}
            </p>
            <div className="mt-3 border-t border-border pt-3">
              <ExchangeRateRowActions rate={rate} {...actions} />
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
