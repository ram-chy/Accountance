"use client";

import Link from "next/link";
import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import type { ExchangeRate } from "@/lib/exchange-rates/types";
import { useExchangeRate } from "@/lib/exchange-rates/use-exchange-rate";
import { formatDateOnly } from "@/lib/format/date";

import { ExchangeRateActionDialog, type ExchangeRateActionKind } from "./exchange-rate-action-dialog";
import { ExchangeRateFormDialog } from "./exchange-rate-form-dialog";
import { ExchangeRatesListSkeleton } from "./exchange-rates-list-skeleton";

/**
 * Exchange-rate detail screen.
 *
 * Reads one rate (`useExchangeRate`) and hosts its edit and lifecycle actions.
 * The direction is stated in full so there is no ambiguity about which value the
 * rate represents. There is no delete; a rate a document priced stays readable
 * forever. A 404 (a missing id, or an id from another company) renders the
 * not-found boundary.
 */

function SummaryItem({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="text-sm text-foreground">{children}</dd>
    </div>
  );
}

function pairLabel(rate: ExchangeRate): string {
  const from = rate.from_currency ?? `#${rate.from_currency_id}`;
  const to = rate.to_currency ?? `#${rate.to_currency_id}`;
  return rate.pair ?? `${from}/${to}`;
}

export function ExchangeRateDetailView({ rateId }: { rateId: number }) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.exchange_rate.view");
  const canUpdate = can("accounting.exchange_rate.update");

  const [reloadToken, setReloadToken] = useState(0);
  const [editKey, setEditKey] = useState<string | null>(null);
  const [action, setAction] = useState<ExchangeRateActionKind | null>(null);

  const detail = useExchangeRate({ rateId, companyId, enabled: canView, reloadToken });
  const reload = () => setReloadToken((token) => token + 1);

  const rate = detail.status === "ready" ? detail.rate : null;

  const header = (() => {
    if (rate === null) {
      return (
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold text-foreground">Exchange rate</h1>
        </div>
      );
    }

    return (
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex flex-col gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-semibold text-foreground">{pairLabel(rate)}</h1>
            <Badge variant={rate.is_active ? "success" : "neutral"}>
              {rate.is_active ? "Active" : "Inactive"}
            </Badge>
          </div>
          <p className="text-sm text-muted-foreground">
            1 {rate.from_currency ?? `#${rate.from_currency_id}`} = {rate.rate}{" "}
            {rate.to_currency ?? `#${rate.to_currency_id}`} · effective{" "}
            {formatDateOnly(rate.effective_date)}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {canUpdate ? (
            <Button variant="secondary" onClick={() => setEditKey(`edit-${rate.id}`)}>
              Edit
            </Button>
          ) : null}
          {canUpdate ? (
            rate.is_active ? (
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
      return (
        <EmptyState
          title="No company selected"
          description="Choose a company to view this exchange rate."
        />
      );
    }

    if (!canView) {
      return (
        <EmptyState
          title="Exchange rates unavailable"
          description="Your role does not include access to exchange rates."
        />
      );
    }

    if (detail.status === "loading") {
      return <ExchangeRatesListSkeleton rows={4} />;
    }

    if (detail.status === "notfound") {
      return (
        <EmptyState
          title="Exchange rate not found"
          description="This exchange rate does not exist for the active company."
          action={
            <Link href="/exchange-rates" className="text-sm font-medium text-primary">
              Back to exchange rates
            </Link>
          }
        />
      );
    }

    if (detail.status === "unavailable") {
      return <EmptyState title="Exchange rate unavailable" description={detail.message} />;
    }

    if (detail.status === "error") {
      return (
        <ErrorState
          title="Could not load this exchange rate"
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

    if (rate === null) {
      return <ExchangeRatesListSkeleton rows={4} />;
    }

    return (
      <section className="rounded-lg border border-border bg-surface p-4 sm:p-6">
        <h2 className="text-sm font-semibold text-foreground">Details</h2>
        <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <SummaryItem label="Pair">{pairLabel(rate)}</SummaryItem>
          <SummaryItem label="From currency">
            {rate.from_currency ?? `#${rate.from_currency_id}`}
          </SummaryItem>
          <SummaryItem label="To currency">{rate.to_currency ?? `#${rate.to_currency_id}`}</SummaryItem>
          <SummaryItem label="Rate">
            1 {rate.from_currency ?? `#${rate.from_currency_id}`} = {rate.rate}{" "}
            {rate.to_currency ?? `#${rate.to_currency_id}`}
          </SummaryItem>
          <SummaryItem label="Effective date">{formatDateOnly(rate.effective_date)}</SummaryItem>
          <SummaryItem label="Source">{rate.source ?? "—"}</SummaryItem>
          <SummaryItem label="Status">{rate.is_active ? "Active" : "Inactive"}</SummaryItem>
          <SummaryItem label="Created">
            {rate.created_at ? formatDateOnly(rate.created_at.slice(0, 10)) : "—"}
          </SummaryItem>
          <SummaryItem label="Last updated">
            {rate.updated_at ? formatDateOnly(rate.updated_at.slice(0, 10)) : "—"}
          </SummaryItem>
        </dl>
      </section>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <Link
        href="/exchange-rates"
        className="text-sm font-medium text-muted-foreground hover:text-foreground"
      >
        ← All exchange rates
      </Link>

      {header}
      {body}

      <ExchangeRateFormDialog
        key={editKey ?? "closed"}
        open={editKey !== null}
        companyId={companyId}
        rate={rate}
        onClose={() => setEditKey(null)}
        onSaved={reload}
      />

      <ExchangeRateActionDialog
        key={action ?? "action"}
        rate={rate}
        action={action}
        onClose={() => setAction(null)}
        onDone={reload}
      />
    </div>
  );
}
