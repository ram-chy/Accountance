"use client";

import Link from "next/link";
import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { useCurrency } from "@/lib/currencies/use-currency";
import { formatDateOnly } from "@/lib/format/date";

import { CurrenciesListSkeleton } from "./currencies-list-skeleton";
import { CurrencyActionDialog, type CurrencyActionKind } from "./currency-action-dialog";
import { CurrencyFormDialog } from "./currency-form-dialog";

/**
 * Currency detail screen.
 *
 * Reads one currency (`useCurrency`) and hosts its edit and lifecycle actions.
 * There is no delete — the lifecycle ends at deactivation. A 404 (an id that
 * does not exist) renders the not-found boundary.
 */

function SummaryItem({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="text-sm text-foreground">{children}</dd>
    </div>
  );
}

function timestamp(value: string | null): string {
  return value === null ? "—" : formatDateOnly(value.slice(0, 10));
}

export function CurrencyDetailView({ currencyId }: { currencyId: number }) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.currency.view");
  const canUpdate = can("accounting.currency.update");
  const canActivate = can("accounting.currency.activate");
  const canDeactivate = can("accounting.currency.deactivate");

  const [reloadToken, setReloadToken] = useState(0);
  const [editKey, setEditKey] = useState<string | null>(null);
  const [action, setAction] = useState<CurrencyActionKind | null>(null);

  const detail = useCurrency({ currencyId, companyId, enabled: canView, reloadToken });
  const reload = () => setReloadToken((token) => token + 1);

  const currency = detail.status === "ready" ? detail.currency : null;

  const header = (() => {
    if (currency === null) {
      return (
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold text-foreground">Currency</h1>
        </div>
      );
    }

    const showLifecycle = currency.is_active ? canDeactivate : canActivate;

    return (
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex flex-col gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-semibold text-foreground">
              {currency.code} · {currency.name}
            </h1>
            <Badge variant={currency.is_active ? "success" : "neutral"}>
              {currency.is_active ? "Active" : "Inactive"}
            </Badge>
          </div>
          <p className="text-sm text-muted-foreground">
            Decimal places: {currency.decimal_precision}
            {currency.symbol ? ` · Symbol ${currency.symbol}` : ""}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {canUpdate ? (
            <Button variant="secondary" onClick={() => setEditKey(`edit-${currency.id}`)}>
              Edit
            </Button>
          ) : null}
          {showLifecycle ? (
            currency.is_active ? (
              <Button variant="ghost" onClick={() => setAction("deactivate")}>
                Deactivate
              </Button>
            ) : (
              <Button variant="ghost" onClick={() => setAction("activate")}>
                Activate
              </Button>
            )
          ) : null}
        </div>
      </div>
    );
  })();

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view this currency." />;
    }

    if (!canView) {
      return (
        <EmptyState
          title="Currencies unavailable"
          description="Your role does not include access to currencies."
        />
      );
    }

    if (detail.status === "loading") {
      return <CurrenciesListSkeleton rows={4} />;
    }

    if (detail.status === "notfound") {
      return (
        <EmptyState
          title="Currency not found"
          description="This currency does not exist."
          action={
            <Link href="/currencies" className="text-sm font-medium text-primary">
              Back to currencies
            </Link>
          }
        />
      );
    }

    if (detail.status === "unavailable") {
      return <EmptyState title="Currency unavailable" description={detail.message} />;
    }

    if (detail.status === "error") {
      return (
        <ErrorState
          title="Could not load this currency"
          message={detail.message}
          requestId={detail.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      );
    }

    if (currency === null) {
      return <CurrenciesListSkeleton rows={4} />;
    }

    return (
      <section className="rounded-lg border border-border bg-surface p-4 sm:p-6">
        <h2 className="text-sm font-semibold text-foreground">Details</h2>
        <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <SummaryItem label="Code">{currency.code}</SummaryItem>
          <SummaryItem label="Name">{currency.name}</SummaryItem>
          <SummaryItem label="Symbol">{currency.symbol ?? "—"}</SummaryItem>
          <SummaryItem label="Decimal places">{currency.decimal_precision}</SummaryItem>
          <SummaryItem label="Status">{currency.is_active ? "Active" : "Inactive"}</SummaryItem>
          <SummaryItem label="Created">{timestamp(currency.created_at)}</SummaryItem>
          <SummaryItem label="Last updated">{timestamp(currency.updated_at)}</SummaryItem>
        </dl>
      </section>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <Link href="/currencies" className="text-sm font-medium text-muted-foreground hover:text-foreground">
        ← All currencies
      </Link>

      {header}
      {body}

      <CurrencyFormDialog
        key={editKey ?? "closed"}
        open={editKey !== null}
        currency={currency}
        onClose={() => setEditKey(null)}
        onSaved={reload}
      />

      <CurrencyActionDialog
        key={action ?? "action"}
        currency={currency}
        action={action}
        onClose={() => setAction(null)}
        onDone={reload}
      />
    </div>
  );
}
