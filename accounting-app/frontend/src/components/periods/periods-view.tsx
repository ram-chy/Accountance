"use client";

import { useMemo, useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Input } from "@/components/ui/field";
import { useFinancialYearOptions } from "@/lib/financial-years/use-financial-years";
import { PERIOD_STATUS_VALUES, type AccountingPeriod, type PeriodStatusValue } from "@/lib/periods/types";
import { PERIOD_LIST_PER_PAGE, usePeriodList } from "@/lib/periods/use-periods";

import { ClosingCheckDialog } from "./closing-check-dialog";
import { PeriodFormDialog } from "./period-form-dialog";
import { PeriodReopenDialog } from "./period-reopen-dialog";
import { PeriodsListSkeleton } from "./periods-list-skeleton";
import { PeriodsTable } from "./periods-table";

/**
 * Accounting-periods screen.
 *
 * Composes the filter bar (status, financial year, date range), the responsive
 * period list and its pagination, and hosts the create/edit dialog, the
 * read-only closing review and the reopen confirmation. Reads go through
 * `usePeriodList`; the closing review has its own `useClosingCheck`. Every write
 * refreshes the list by bumping a reload token so it reflects authoritative
 * backend state. Filter changes reset the page to 1 in the same state update.
 */

type PeriodQuery = {
  status: PeriodStatusValue | "";
  financial_year_id: number | null;
  from_date: string;
  to_date: string;
  page: number;
};

const INITIAL_QUERY: PeriodQuery = {
  status: "",
  financial_year_id: null,
  from_date: "",
  to_date: "",
  page: 1,
};

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function statusLabel(value: PeriodStatusValue): string {
  return value === "OPEN" ? "Open" : "Closed";
}

type Editor = { mode: "create" } | { mode: "edit"; period: AccountingPeriod } | null;

export function PeriodsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.periods.view");
  const canCreate = can("accounting.periods.create");
  const canUpdate = can("accounting.periods.update");
  const canClose = can("accounting.periods.close");
  const canReopen = can("accounting.periods.reopen");

  const [query, setQuery] = useState<PeriodQuery>(INITIAL_QUERY);
  const [reloadToken, setReloadToken] = useState(0);
  const [editor, setEditor] = useState<Editor>(null);
  const [reviewing, setReviewing] = useState<AccountingPeriod | null>(null);
  const [reopening, setReopening] = useState<AccountingPeriod | null>(null);

  const filters = useMemo(
    () => ({
      status: query.status,
      financial_year_id: query.financial_year_id,
      from_date: query.from_date,
      to_date: query.to_date,
    }),
    [query.status, query.financial_year_id, query.from_date, query.to_date],
  );

  const state = usePeriodList({
    companyId,
    enabled: canView,
    filters,
    page: query.page,
    perPage: PERIOD_LIST_PER_PAGE,
    reloadToken,
  });

  const yearsState = useFinancialYearOptions(companyId, canView, reloadToken);
  const yearOptions = yearsState.status === "ready" ? yearsState.items : [];

  const reload = () => setReloadToken((token) => token + 1);

  const hasFilters =
    query.status !== "" ||
    query.financial_year_id !== null ||
    query.from_date !== "" ||
    query.to_date !== "";

  const header = (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">Accounting periods</h1>
        <p className="text-sm text-muted-foreground">
          Review, close and reopen the posting windows for the active company.
        </p>
      </div>
      {canCreate ? (
        <Button onClick={() => setEditor({ mode: "create" })}>New period</Button>
      ) : null}
    </div>
  );

  const filterBar = (
    <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="periods-status" className="text-sm font-medium text-foreground">
          Status
        </label>
        <select
          id="periods-status"
          className={selectClasses}
          value={query.status}
          onChange={(event) =>
            setQuery((current) => ({
              ...current,
              status: event.target.value as PeriodStatusValue | "",
              page: 1,
            }))
          }
          disabled={!activeCompany}
        >
          <option value="">All statuses</option>
          {PERIOD_STATUS_VALUES.map((value) => (
            <option key={value} value={value}>
              {statusLabel(value)}
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="periods-year" className="text-sm font-medium text-foreground">
          Financial year
        </label>
        <select
          id="periods-year"
          className={selectClasses}
          value={query.financial_year_id === null ? "" : String(query.financial_year_id)}
          onChange={(event) =>
            setQuery((current) => ({
              ...current,
              financial_year_id: event.target.value === "" ? null : Number(event.target.value),
              page: 1,
            }))
          }
          disabled={!activeCompany}
        >
          <option value="">All financial years</option>
          {yearOptions.map((option) => (
            <option key={option.id} value={String(option.id)}>
              {option.name}
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="periods-from" className="text-sm font-medium text-foreground">
          Covering from
        </label>
        <Input
          id="periods-from"
          type="date"
          value={query.from_date}
          onChange={(event) =>
            setQuery((current) => ({ ...current, from_date: event.target.value, page: 1 }))
          }
          disabled={!activeCompany}
        />
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="periods-to" className="text-sm font-medium text-foreground">
          Covering to
        </label>
        <Input
          id="periods-to"
          type="date"
          value={query.to_date}
          onChange={(event) =>
            setQuery((current) => ({ ...current, to_date: event.target.value, page: 1 }))
          }
          disabled={!activeCompany}
        />
      </div>

      {hasFilters ? (
        <Button variant="ghost" onClick={() => setQuery(INITIAL_QUERY)}>
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
          description="Choose a company to view its accounting periods."
        />
      );
    }

    if (!canView) {
      return (
        <EmptyState
          title="Accounting periods unavailable"
          description="Your role does not include access to accounting periods."
        />
      );
    }

    if (state.status === "loading") {
      return <PeriodsListSkeleton />;
    }

    if (state.status === "unavailable") {
      return <EmptyState title="Accounting periods unavailable" description={state.message} />;
    }

    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load accounting periods"
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
            title="No periods on this page"
            description="This page is empty. Return to the previous page to see the remaining periods."
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

      if (hasFilters) {
        return (
          <EmptyState
            title="No periods match"
            description="No accounting periods match the current filters."
            action={
              <Button variant="secondary" onClick={() => setQuery(INITIAL_QUERY)}>
                Clear filters
              </Button>
            }
          />
        );
      }

      return (
        <EmptyState
          title="No accounting periods yet"
          description="Create a period, or generate the months of a financial year from the Financial years screen."
          action={
            canCreate ? <Button onClick={() => setEditor({ mode: "create" })}>New period</Button> : undefined
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <PeriodsTable
          items={state.items}
          canUpdate={canUpdate}
          canReopen={canReopen}
          onReview={(period) => setReviewing(period)}
          onEdit={(period) => setEditor({ mode: "edit", period })}
          onReopen={(period) => setReopening(period)}
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
            Showing {state.items.length} period{state.items.length === 1 ? "" : "s"} on page{" "}
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

      <PeriodFormDialog
        key={editor?.mode === "edit" ? `edit-${editor.period.id}` : "create"}
        editor={editor}
        onClose={() => setEditor(null)}
        onSaved={reload}
        yearOptions={yearOptions}
      />

      <ClosingCheckDialog
        key={reviewing?.id ?? "review"}
        period={reviewing}
        canClose={canClose}
        onClose={() => setReviewing(null)}
        onDone={reload}
      />

      <PeriodReopenDialog
        period={reopening}
        onClose={() => setReopening(null)}
        onDone={reload}
      />
    </div>
  );
}
