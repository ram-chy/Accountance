"use client";

import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import type { Currency } from "@/lib/currencies/types";
import { useCurrencyList } from "@/lib/currencies/use-currencies";

import { CurrenciesListSkeleton } from "./currencies-list-skeleton";
import { CurrenciesTable } from "./currencies-table";
import { CurrencyActionDialog, type CurrencyActionKind } from "./currency-action-dialog";
import { CurrencyFormDialog } from "./currency-form-dialog";

/**
 * Currency master screen.
 *
 * The filter bar carries the one filter the index endpoint supports: whether to
 * list only active currencies. The list is unpaginated by contract (ordered by
 * `code`), so there is no page state. Reads go through `useCurrencyList`; every
 * write (create/edit, lifecycle) refreshes the list by bumping a reload token so
 * it reflects authoritative backend state.
 *
 * Currencies are global reference data, so the write controls are gated on the
 * `accounting.currency.*` capabilities — held by Admin alone. The backend
 * re-authorises every call; this gating only decides what is rendered.
 */

type ActionTarget = { currency: Currency; action: CurrencyActionKind };

type EditorTarget = { currency: Currency | null; key: string };

export function CurrenciesView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.currency.view");
  const canCreate = can("accounting.currency.create");
  const canUpdate = can("accounting.currency.update");
  const canActivate = can("accounting.currency.activate");
  const canDeactivate = can("accounting.currency.deactivate");

  const [activeOnly, setActiveOnly] = useState(false);
  const [reloadToken, setReloadToken] = useState(0);
  const [editor, setEditor] = useState<EditorTarget | null>(null);
  const [actionTarget, setActionTarget] = useState<ActionTarget | null>(null);

  const state = useCurrencyList({
    companyId,
    enabled: canView,
    filters: { active_only: activeOnly },
    reloadToken,
  });

  const reload = () => setReloadToken((token) => token + 1);

  const openCreate = () => setEditor({ currency: null, key: "create" });
  const openEdit = (currency: Currency) => setEditor({ currency, key: `edit-${currency.id}` });

  const header = (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">Currencies</h1>
        <p className="text-sm text-muted-foreground">
          Global currency reference data: the units every company may transact in and define rates
          against.
        </p>
      </div>
      {canCreate ? <Button onClick={openCreate}>New currency</Button> : null}
    </div>
  );

  const filterBar = (
    <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="currencies-active-only" className="text-sm font-medium text-foreground">
          Show
        </label>
        <select
          id="currencies-active-only"
          className="h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground"
          value={activeOnly ? "active" : "all"}
          onChange={(event) => setActiveOnly(event.target.value === "active")}
          disabled={!activeCompany}
        >
          <option value="all">All currencies</option>
          <option value="active">Active currencies only</option>
        </select>
      </div>
    </div>
  );

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view currencies." />;
    }

    if (!canView) {
      return (
        <EmptyState
          title="Currencies unavailable"
          description="Your role does not include access to currencies."
        />
      );
    }

    if (state.status === "loading") {
      return <CurrenciesListSkeleton />;
    }

    if (state.status === "unavailable") {
      return <EmptyState title="Currencies unavailable" description={state.message} />;
    }

    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load currencies"
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
      if (activeOnly) {
        return (
          <EmptyState
            title="No active currencies"
            description="No active currencies match the current filter."
            action={
              <Button variant="secondary" onClick={() => setActiveOnly(false)}>
                Show all currencies
              </Button>
            }
          />
        );
      }

      return (
        <EmptyState
          title="No currencies yet"
          description="Create a currency before defining exchange rates or transacting in it."
          action={canCreate ? <Button onClick={openCreate}>New currency</Button> : undefined}
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <CurrenciesTable
          items={state.items}
          canUpdate={canUpdate}
          canActivate={canActivate}
          canDeactivate={canDeactivate}
          onEdit={openEdit}
          onActivate={(currency) => setActionTarget({ currency, action: "activate" })}
          onDeactivate={(currency) => setActionTarget({ currency, action: "deactivate" })}
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
            Showing {state.items.length} currenc{state.items.length === 1 ? "y" : "ies"}.
          </p>
        ) : null}

        {body}
      </div>

      <CurrencyFormDialog
        key={editor?.key ?? "closed"}
        open={editor !== null}
        currency={editor?.currency ?? null}
        onClose={() => setEditor(null)}
        onSaved={reload}
      />

      <CurrencyActionDialog
        key={actionTarget ? `${actionTarget.action}-${actionTarget.currency.id}` : "action"}
        currency={actionTarget?.currency ?? null}
        action={actionTarget?.action ?? null}
        onClose={() => setActionTarget(null)}
        onDone={reload}
      />
    </div>
  );
}
