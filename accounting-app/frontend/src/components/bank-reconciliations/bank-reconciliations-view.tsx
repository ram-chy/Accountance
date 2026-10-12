"use client";

import { useMemo, useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Input } from "@/components/ui/field";
import { useAccountOptions } from "@/lib/accounts/use-account-options";
import {
  BANK_RECONCILIATION_STATUS_VALUES,
  bankReconciliationStatusLabel,
  type BankReconciliationStatusValue,
} from "@/lib/bank-reconciliations/types";
import {
  BANK_RECONCILIATION_LIST_PER_PAGE,
  useBankReconciliationList,
} from "@/lib/bank-reconciliations/use-bank-reconciliations";

import { BankReconciliationsListSkeleton } from "./bank-reconciliations-list-skeleton";
import {
  BankReconciliationsTable,
  type ReconciliationAccountLabels,
} from "./bank-reconciliations-table";

/**
 * Bank-reconciliations screen.
 *
 * Composes the filter bar (status, period range), the responsive list and its
 * pagination. Reads go through `useBankReconciliationList`.
 *
 * Only the filters the backend actually enforces are offered. The endpoint also
 * accepts a `bank_account_id` filter, but no read endpoint exposes
 * `bank_accounts.id`, so that filter cannot be populated and is omitted (see the
 * Phase 9 report). Rows are labelled with the ledger account name, resolved from
 * the shared accounts list.
 *
 * There is no "New reconciliation" action: creating one requires the
 * unexposed `bank_accounts.id` (see the Phase 9 report, "known limitations").
 */

type ReconciliationQuery = {
  status: BankReconciliationStatusValue | "";
  from_date: string;
  to_date: string;
  page: number;
};

const INITIAL_QUERY: ReconciliationQuery = {
  status: "",
  from_date: "",
  to_date: "",
  page: 1,
};

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export function BankReconciliationsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.bank_reconciliation.view");
  const canViewAccounts = can("accounts.view");

  const [query, setQuery] = useState<ReconciliationQuery>(INITIAL_QUERY);
  const [reloadToken, setReloadToken] = useState(0);

  const filters = useMemo(
    () => ({
      status: query.status,
      from_date: query.from_date,
      to_date: query.to_date,
    }),
    [query.status, query.from_date, query.to_date],
  );

  const state = useBankReconciliationList({
    companyId,
    enabled: canView,
    filters,
    page: query.page,
    perPage: BANK_RECONCILIATION_LIST_PER_PAGE,
    reloadToken,
  });

  const accountsState = useAccountOptions(companyId, canViewAccounts, reloadToken);

  const labels: ReconciliationAccountLabels = useMemo(() => {
    const map: ReconciliationAccountLabels = {};

    if (accountsState.status === "ready") {
      for (const account of accountsState.items) {
        map[account.id] = `${account.code} — ${account.name}`;
      }
    }

    return map;
  }, [accountsState]);

  const reload = () => setReloadToken((token) => token + 1);

  const hasFilters =
    query.status !== "" || query.from_date !== "" || query.to_date !== "";

  const header = (
    <div className="flex flex-col gap-1">
      <h1 className="text-xl font-semibold text-foreground">Bank reconciliations</h1>
      <p className="text-sm text-muted-foreground">
        Reconcile a bank account ledger against its statement for the active
        company.
      </p>
    </div>
  );

  const filterBar = (
    <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="reconciliations-status" className="text-sm font-medium text-foreground">
          Status
        </label>
        <select
          id="reconciliations-status"
          className={selectClasses}
          value={query.status}
          onChange={(event) =>
            setQuery((current) => ({
              ...current,
              status: event.target.value as BankReconciliationStatusValue | "",
              page: 1,
            }))
          }
          disabled={!activeCompany}
        >
          <option value="">All statuses</option>
          {BANK_RECONCILIATION_STATUS_VALUES.map((value) => (
            <option key={value} value={value}>
              {bankReconciliationStatusLabel(value)}
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="reconciliations-from" className="text-sm font-medium text-foreground">
          Period from
        </label>
        <Input
          id="reconciliations-from"
          type="date"
          value={query.from_date}
          onChange={(event) =>
            setQuery((current) => ({ ...current, from_date: event.target.value, page: 1 }))
          }
          disabled={!activeCompany}
        />
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="reconciliations-to" className="text-sm font-medium text-foreground">
          Period to
        </label>
        <Input
          id="reconciliations-to"
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
          description="Choose a company to view its bank reconciliations."
        />
      );
    }

    if (!canView) {
      return (
        <EmptyState
          title="Bank reconciliations unavailable"
          description="Your role does not include access to bank reconciliations."
        />
      );
    }

    if (state.status === "loading") {
      return <BankReconciliationsListSkeleton />;
    }

    if (state.status === "unavailable") {
      return <EmptyState title="Bank reconciliations unavailable" description={state.message} />;
    }

    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load bank reconciliations"
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
            title="No reconciliations on this page"
            description="This page is empty. Return to the previous page to see the remaining reconciliations."
            action={
              <Button
                variant="secondary"
                onClick={() =>
                  setQuery((current) => ({ ...current, page: Math.max(1, current.page - 1) }))
                }
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
            title="No reconciliations match"
            description="No bank reconciliations match the current filters."
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
          title="No bank reconciliations yet"
          description="Reconciliations are created from the API; they will appear here once they exist."
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <BankReconciliationsTable items={state.items} labels={labels} />
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
            Showing {state.items.length} reconciliation{state.items.length === 1 ? "" : "s"} on page{" "}
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
    </div>
  );
}
