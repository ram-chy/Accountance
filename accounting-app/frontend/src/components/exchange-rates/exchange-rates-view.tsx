"use client";

import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { useCurrencyOptions } from "@/lib/currencies/use-currency-options";
import type { ExchangeRate } from "@/lib/exchange-rates/types";
import { useExchangeRateList } from "@/lib/exchange-rates/use-exchange-rates";

import { ExchangeRateActionDialog, type ExchangeRateActionKind } from "./exchange-rate-action-dialog";
import { ExchangeRateFormDialog } from "./exchange-rate-form-dialog";
import { ExchangeRatesListSkeleton } from "./exchange-rates-list-skeleton";
import { ExchangeRatesTable } from "./exchange-rates-table";

/**
 * Exchange-rate screen.
 *
 * The filter bar carries the filters the index endpoint supports: a from
 * currency, a to currency and whether to list only active rates. There is no
 * effective-date filter (the endpoint has none) and the list is unpaginated by
 * contract, so there is no page state. Reads go through `useExchangeRateList`;
 * every write (create/edit, lifecycle) refreshes the list by bumping a reload
 * token so it reflects authoritative backend state.
 *
 * Activation and deactivation ride on `accounting.exchange_rate.update`, so all
 * write controls share that one capability. The backend re-authorises every call.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type ActionTarget = { rate: ExchangeRate; action: ExchangeRateActionKind };
type EditorTarget = { rate: ExchangeRate | null; key: string };

export function ExchangeRatesView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.exchange_rate.view");
  const canCreate = can("accounting.exchange_rate.create");
  const canUpdate = can("accounting.exchange_rate.update");

  const [fromId, setFromId] = useState("");
  const [toId, setToId] = useState("");
  const [activeOnly, setActiveOnly] = useState(false);
  const [reloadToken, setReloadToken] = useState(0);
  const [editor, setEditor] = useState<EditorTarget | null>(null);
  const [actionTarget, setActionTarget] = useState<ActionTarget | null>(null);

  const currencyOptions = useCurrencyOptions(companyId, canView);
  const currencies = currencyOptions.status === "ready" ? currencyOptions.items : [];

  const filters = {
    from_currency_id: fromId === "" ? undefined : Number(fromId),
    to_currency_id: toId === "" ? undefined : Number(toId),
    active_only: activeOnly,
  };

  const state = useExchangeRateList({
    companyId,
    enabled: canView,
    filters,
    reloadToken,
  });

  const reload = () => setReloadToken((token) => token + 1);
  const clearFilters = () => {
    setFromId("");
    setToId("");
    setActiveOnly(false);
  };

  const openCreate = () => setEditor({ rate: null, key: "create" });
  const openEdit = (rate: ExchangeRate) => setEditor({ rate, key: `edit-${rate.id}` });

  const filtersActive = fromId !== "" || toId !== "" || activeOnly;

  const header = (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">Exchange rates</h1>
        <p className="text-sm text-muted-foreground">
          Dated history for this company: units of the target currency per one unit of the source.
          To change a rate, record it on a new effective date.
        </p>
      </div>
      {canCreate ? <Button onClick={openCreate}>New exchange rate</Button> : null}
    </div>
  );

  const filterBar = (
    <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
      <div className="grid gap-3 sm:grid-cols-3">
        <div className="flex flex-col gap-1.5">
          <label htmlFor="exchange-rates-from" className="text-sm font-medium text-foreground">
            From currency
          </label>
          <select
            id="exchange-rates-from"
            className={selectClasses}
            value={fromId}
            onChange={(event) => setFromId(event.target.value)}
            disabled={!activeCompany || currencyOptions.status !== "ready"}
          >
            <option value="">Any currency</option>
            {currencies.map((currency) => (
              <option key={currency.id} value={currency.id}>
                {currency.code} — {currency.name}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="exchange-rates-to" className="text-sm font-medium text-foreground">
            To currency
          </label>
          <select
            id="exchange-rates-to"
            className={selectClasses}
            value={toId}
            onChange={(event) => setToId(event.target.value)}
            disabled={!activeCompany || currencyOptions.status !== "ready"}
          >
            <option value="">Any currency</option>
            {currencies.map((currency) => (
              <option key={currency.id} value={currency.id}>
                {currency.code} — {currency.name}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="exchange-rates-active-only" className="text-sm font-medium text-foreground">
            Show
          </label>
          <select
            id="exchange-rates-active-only"
            className={selectClasses}
            value={activeOnly ? "active" : "all"}
            onChange={(event) => setActiveOnly(event.target.value === "active")}
            disabled={!activeCompany}
          >
            <option value="all">All rates</option>
            <option value="active">Active rates only</option>
          </select>
        </div>
      </div>

      {filtersActive ? (
        <div>
          <Button variant="ghost" size="sm" onClick={clearFilters}>
            Clear filters
          </Button>
        </div>
      ) : null}
    </div>
  );

  const body = (() => {
    if (!activeCompany) {
      return (
        <EmptyState title="No company selected" description="Choose a company to view its exchange rates." />
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

    if (state.status === "loading") {
      return <ExchangeRatesListSkeleton />;
    }

    if (state.status === "unavailable") {
      return <EmptyState title="Exchange rates unavailable" description={state.message} />;
    }

    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load exchange rates"
          message={state.message}
          requestId={state.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      );
    }

    if (state.items.length === 0) {
      if (filtersActive) {
        return (
          <EmptyState
            title="No matching rates"
            description="No exchange rates match the current filters."
            action={
              <Button variant="secondary" onClick={clearFilters}>
                Clear filters
              </Button>
            }
          />
        );
      }

      return (
        <EmptyState
          title="No exchange rates yet"
          description="Record a rate before pricing a foreign-currency document."
          action={canCreate ? <Button onClick={openCreate}>New exchange rate</Button> : undefined}
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <ExchangeRatesTable
          items={state.items}
          canUpdate={canUpdate}
          onEdit={openEdit}
          onActivate={(rate) => setActionTarget({ rate, action: "activate" })}
          onDeactivate={(rate) => setActionTarget({ rate, action: "deactivate" })}
        />
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      {header}
      {filterBar}

      <div className="flex flex-col gap-4">
        {state.status === "ready" && state.items.length > 0 ? (
          <p className="text-sm text-muted-foreground">
            Showing {state.items.length} rate{state.items.length === 1 ? "" : "s"}.
          </p>
        ) : null}

        {body}
      </div>

      <ExchangeRateFormDialog
        key={editor?.key ?? "closed"}
        open={editor !== null}
        companyId={companyId}
        rate={editor?.rate ?? null}
        onClose={() => setEditor(null)}
        onSaved={reload}
      />

      <ExchangeRateActionDialog
        key={actionTarget ? `${actionTarget.action}-${actionTarget.rate.id}` : "action"}
        rate={actionTarget?.rate ?? null}
        action={actionTarget?.action ?? null}
        onClose={() => setActionTarget(null)}
        onDone={reload}
      />
    </div>
  );
}
