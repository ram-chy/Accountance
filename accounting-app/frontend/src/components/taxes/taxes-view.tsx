"use client";

import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { useTaxList } from "@/lib/taxes/use-taxes";
import type { Tax } from "@/lib/taxes/types";

import { TaxActionDialog, type TaxActionKind } from "./tax-action-dialog";
import { TaxFormDialog, type TaxEditorMode } from "./tax-form-dialog";
import { TaxesListSkeleton } from "./taxes-list-skeleton";
import { TaxesTable } from "./taxes-table";

/**
 * Taxes master screen.
 *
 * The filter bar carries the one filter the index endpoint supports: whether to
 * list only active taxes. The list itself is unpaginated by contract, so there is
 * no page state. Reads go through `useTaxList`; every write (create/edit, lifecycle,
 * delete) refreshes the list by bumping a reload token so it reflects
 * authoritative backend state.
 */

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type ActionTarget = { tax: Tax; action: TaxActionKind };

export function TaxesView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.tax.view");
  const canCreate = can("accounting.tax.create");
  const canUpdate = can("accounting.tax.update");
  const canDelete = can("accounting.tax.delete");

  const [activeOnly, setActiveOnly] = useState(false);
  const [reloadToken, setReloadToken] = useState(0);
  const [editor, setEditor] = useState<TaxEditorMode | null>(null);
  const [actionTarget, setActionTarget] = useState<ActionTarget | null>(null);

  const state = useTaxList({
    companyId,
    enabled: canView,
    filters: { active_only: activeOnly },
    reloadToken,
  });

  const reload = () => setReloadToken((token) => token + 1);

  const header = (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">Taxes</h1>
        <p className="text-sm text-muted-foreground">
          Configure the taxes the active company charges and recovers, and where their money
          posts.
        </p>
      </div>
      {canCreate ? <Button onClick={() => setEditor({ mode: "create" })}>New tax</Button> : null}
    </div>
  );

  const filterBar = (
    <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="taxes-active-only" className="text-sm font-medium text-foreground">
          Show
        </label>
        <select
          id="taxes-active-only"
          className={selectClasses}
          value={activeOnly ? "active" : "all"}
          onChange={(event) => setActiveOnly(event.target.value === "active")}
          disabled={!activeCompany}
        >
          <option value="all">All taxes</option>
          <option value="active">Active taxes only</option>
        </select>
      </div>
    </div>
  );

  const body = (() => {
    if (!activeCompany) {
      return (
        <EmptyState
          title="No company selected"
          description="Choose a company to view its taxes."
        />
      );
    }

    if (!canView) {
      return (
        <EmptyState
          title="Taxes unavailable"
          description="Your role does not include access to taxes."
        />
      );
    }

    if (state.status === "loading") {
      return <TaxesListSkeleton />;
    }

    if (state.status === "unavailable") {
      return <EmptyState title="Taxes unavailable" description={state.message} />;
    }

    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load taxes"
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
            title="No active taxes"
            description="No active taxes match the current filter."
            action={
              <Button variant="secondary" onClick={() => setActiveOnly(false)}>
                Show all taxes
              </Button>
            }
          />
        );
      }

      return (
        <EmptyState
          title="No taxes yet"
          description="Create a tax to start charging it on sales or recovering it on purchases."
          action={
            canCreate ? <Button onClick={() => setEditor({ mode: "create" })}>New tax</Button> : undefined
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <TaxesTable
          items={state.items}
          canUpdate={canUpdate}
          canDelete={canDelete}
          onEdit={(tax) => setEditor({ mode: "edit", tax })}
          onActivate={(tax) => setActionTarget({ tax, action: "activate" })}
          onDeactivate={(tax) => setActionTarget({ tax, action: "deactivate" })}
          onDelete={(tax) => setActionTarget({ tax, action: "delete" })}
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
            Showing {state.items.length} tax{state.items.length === 1 ? "" : "es"}.
          </p>
        ) : null}

        {body}
      </div>

      <TaxFormDialog
        key={editor?.mode === "edit" ? `edit-${editor.tax.id}` : "create"}
        editor={editor}
        onClose={() => setEditor(null)}
        onSaved={reload}
      />

      <TaxActionDialog
        key={actionTarget ? `${actionTarget.action}-${actionTarget.tax.id}` : "action"}
        tax={actionTarget?.tax ?? null}
        action={actionTarget?.action ?? null}
        onClose={() => setActionTarget(null)}
        onDone={reload}
      />
    </div>
  );
}
