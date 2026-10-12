"use client";

import { useMemo, useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import {
  FINANCIAL_YEAR_LIST_PER_PAGE,
  useFinancialYearList,
} from "@/lib/financial-years/use-financial-years";
import {
  FINANCIAL_YEAR_STATUS_VALUES,
  type FinancialYear,
  type FinancialYearFilters,
  type FinancialYearStatusValue,
} from "@/lib/financial-years/types";

import {
  FinancialYearActionDialog,
  type FinancialYearAction,
} from "./financial-year-action-dialog";
import { FinancialYearFormDialog } from "./financial-year-form-dialog";
import { FinancialYearsListSkeleton } from "./financial-years-list-skeleton";
import { FinancialYearsTable } from "./financial-years-table";

/**
 * Financial-years screen.
 *
 * Composes the status filter, the responsive year list and its pagination, and
 * hosts the create/edit and action (generate/finalize) dialogs. All reads go
 * through `useFinancialYearList`; every write goes through a dialog and refreshes
 * the list by bumping a reload token so it always shows authoritative backend
 * state. Financial years share the periods permission set, so the page is gated
 * by `accounting.periods.view`.
 */

type FinancialYearQuery = {
  status: FinancialYearStatusValue | "";
  page: number;
};

const INITIAL_QUERY: FinancialYearQuery = { status: "", page: 1 };

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function statusLabel(value: FinancialYearStatusValue): string {
  return value === "OPEN" ? "Open" : "Closed";
}

type Editor = { mode: "create" } | { mode: "edit"; year: FinancialYear } | null;

export function FinancialYearsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.periods.view");
  const canCreate = can("accounting.periods.create");
  const canUpdate = can("accounting.periods.update");
  const canClose = can("accounting.periods.close");

  const [query, setQuery] = useState<FinancialYearQuery>(INITIAL_QUERY);
  const [reloadToken, setReloadToken] = useState(0);
  const [editor, setEditor] = useState<Editor>(null);
  const [action, setAction] = useState<FinancialYearAction | null>(null);

  const filters = useMemo<FinancialYearFilters>(
    () => ({ status: query.status }),
    [query.status],
  );

  const state = useFinancialYearList({
    companyId,
    enabled: canView,
    filters,
    page: query.page,
    perPage: FINANCIAL_YEAR_LIST_PER_PAGE,
    reloadToken,
  });

  const reload = () => setReloadToken((token) => token + 1);

  const isFiltered = query.status !== "";

  const onClearFilters = () => setQuery(INITIAL_QUERY);

  const header = (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">Financial years</h1>
        <p className="text-sm text-muted-foreground">
          The dated containers that hold accounting periods for the active company.
        </p>
      </div>
      {canCreate ? (
        <Button onClick={() => setEditor({ mode: "create" })}>New financial year</Button>
      ) : null}
    </div>
  );

  const filterBar = (
    <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="financial-years-status" className="text-sm font-medium text-foreground">
          Status
        </label>
        <select
          id="financial-years-status"
          className={selectClasses}
          value={query.status}
          onChange={(event) =>
            setQuery({
              status: event.target.value as FinancialYearStatusValue | "",
              page: 1,
            })
          }
          disabled={!activeCompany}
        >
          <option value="">All statuses</option>
          {FINANCIAL_YEAR_STATUS_VALUES.map((value) => (
            <option key={value} value={value}>
              {statusLabel(value)}
            </option>
          ))}
        </select>
      </div>

      {isFiltered ? (
        <Button variant="ghost" onClick={onClearFilters}>
          Clear
        </Button>
      ) : null}
    </div>
  );

  const body = (() => {
    if (!activeCompany) {
      return (
        <EmptyState
          title="No company selected"
          description="Choose a company to view its financial years."
        />
      );
    }

    if (!canView) {
      return (
        <EmptyState
          title="Financial years unavailable"
          description="Your role does not include access to financial years."
        />
      );
    }

    if (state.status === "loading") {
      return <FinancialYearsListSkeleton />;
    }

    if (state.status === "unavailable") {
      return <EmptyState title="Financial years unavailable" description={state.message} />;
    }

    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load financial years"
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
      if (query.page > 1) {
        return (
          <EmptyState
            title="No financial years on this page"
            description="This page is empty. Return to the previous page to see the remaining years."
            action={
              <Button
                variant="secondary"
                onClick={() => setQuery((current) => ({ ...current, page: Math.max(1, current.page - 1) }))}
              >
                Previous page
              </Button>
            }
          />
        );
      }

      if (isFiltered) {
        return (
          <EmptyState
            title="No financial years match"
            description="No financial years match the current status filter."
            action={
              <Button variant="secondary" onClick={onClearFilters}>
                Clear filters
              </Button>
            }
          />
        );
      }

      return (
        <EmptyState
          title="No financial years yet"
          description="Create the first financial year to start organising this company's accounting periods."
          action={
            canCreate ? (
              <Button onClick={() => setEditor({ mode: "create" })}>New financial year</Button>
            ) : undefined
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <FinancialYearsTable
          items={state.items}
          canCreate={canCreate}
          canUpdate={canUpdate}
          canClose={canClose}
          onEdit={(year) => setEditor({ mode: "edit", year })}
          onAction={(nextAction) => setAction(nextAction)}
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
            Showing {state.items.length} financial year{state.items.length === 1 ? "" : "s"} on page{" "}
            {query.page}
            {state.hasMore ? "; more may exist on later pages." : "."}
          </p>
        ) : null}

        {body}

        {state.status === "ready" && (state.items.length > 0 || query.page > 1) ? (
          <div className="flex items-center justify-between gap-3">
            <Button
              variant="secondary"
              disabled={query.page <= 1}
              onClick={() => setQuery((current) => ({ ...current, page: Math.max(1, current.page - 1) }))}
            >
              Previous
            </Button>
            <span className="text-sm text-muted-foreground">Page {query.page}</span>
            <Button
              variant="secondary"
              disabled={!state.hasMore}
              onClick={() => setQuery((current) => ({ ...current, page: current.page + 1 }))}
            >
              Next
            </Button>
          </div>
        ) : null}
      </div>

      <FinancialYearFormDialog
        key={editor?.mode === "edit" ? `edit-${editor.year.id}` : "create"}
        editor={editor}
        onClose={() => setEditor(null)}
        onSaved={reload}
      />

      <FinancialYearActionDialog
        action={action}
        onClose={() => setAction(null)}
        onDone={reload}
      />
    </div>
  );
}
