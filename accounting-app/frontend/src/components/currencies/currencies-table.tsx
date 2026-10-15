"use client";

import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import type { Currency } from "@/lib/currencies/types";

/**
 * Responsive currency list: a table on medium+ screens and stacked cards below.
 *
 * Columns follow the verified `CurrencyResource` — code, name, symbol, decimal
 * precision and active state. Actions are permission-gated and the backend
 * re-authorises every call.
 */

function StatusCell({ currency }: { currency: Currency }) {
  return (
    <Badge variant={currency.is_active ? "success" : "neutral"}>
      {currency.is_active ? "Active" : "Inactive"}
    </Badge>
  );
}

function NameCell({ currency }: { currency: Currency }) {
  return (
    <>
      <span className="font-medium">{currency.name}</span>
      <span className="block text-xs text-muted-foreground">
        {currency.symbol ? `${currency.symbol} · ` : ""}
        {currency.decimal_precision} decimal{currency.decimal_precision === 1 ? "" : "s"}
      </span>
    </>
  );
}

export type CurrencyRowActionsProps = {
  currency: Currency;
  canUpdate: boolean;
  canActivate: boolean;
  canDeactivate: boolean;
  onEdit: (currency: Currency) => void;
  onActivate: (currency: Currency) => void;
  onDeactivate: (currency: Currency) => void;
};

function CurrencyRowActions({
  currency,
  canUpdate,
  canActivate,
  canDeactivate,
  onEdit,
  onActivate,
  onDeactivate,
}: CurrencyRowActionsProps) {
  const showLifecycle = currency.is_active ? canDeactivate : canActivate;

  return (
    <div className="flex flex-wrap items-center justify-end gap-1.5">
      <Link
        href={`/currencies/${currency.id}`}
        className="inline-flex h-8 items-center justify-center rounded-md border border-border bg-surface px-3 text-sm font-medium text-foreground transition-colors hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        View
      </Link>

      {canUpdate ? (
        <Button size="sm" variant="secondary" onClick={() => onEdit(currency)}>
          Edit
        </Button>
      ) : null}

      {showLifecycle ? (
        currency.is_active ? (
          <Button size="sm" variant="ghost" onClick={() => onDeactivate(currency)}>
            Deactivate
          </Button>
        ) : (
          <Button size="sm" variant="ghost" onClick={() => onActivate(currency)}>
            Activate
          </Button>
        )
      ) : null}
    </div>
  );
}

export type CurrenciesTableProps = Omit<CurrencyRowActionsProps, "currency"> & {
  items: Currency[];
};

export function CurrenciesTable({
  items,
  canUpdate,
  canActivate,
  canDeactivate,
  onEdit,
  onActivate,
  onDeactivate,
}: CurrenciesTableProps) {
  const actions = { canUpdate, canActivate, canDeactivate, onEdit, onActivate, onDeactivate };

  return (
    <>
      <table className="hidden w-full border-collapse text-left md:table">
        <caption className="sr-only">Currencies</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Code
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Name
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
          {items.map((currency) => (
            <tr key={currency.id} className="border-b border-border last:border-0">
              <td className="px-3 py-2 text-sm font-medium text-foreground">{currency.code}</td>
              <td className="px-3 py-2 text-sm text-foreground">
                <NameCell currency={currency} />
              </td>
              <td className="px-3 py-2">
                <StatusCell currency={currency} />
              </td>
              <td className="px-3 py-2">
                <CurrencyRowActions currency={currency} {...actions} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <ul className="flex flex-col gap-3 md:hidden">
        {items.map((currency) => (
          <li key={currency.id} className="rounded-lg border border-border bg-surface p-4">
            <div className="flex items-baseline justify-between gap-3">
              <p className="text-sm font-medium text-foreground">
                {currency.code} · {currency.name}
              </p>
              <StatusCell currency={currency} />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
              {currency.symbol ? `Symbol ${currency.symbol} · ` : ""}
              {currency.decimal_precision} decimal{currency.decimal_precision === 1 ? "" : "s"}
            </p>
            <div className="mt-3 border-t border-border pt-3">
              <CurrencyRowActions currency={currency} {...actions} />
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
