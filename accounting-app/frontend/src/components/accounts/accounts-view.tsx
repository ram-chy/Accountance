"use client";

import { useEffect, useMemo, useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Input } from "@/components/ui/field";
import {
  ACCOUNT_LIST_PER_PAGE,
  useAccountList,
  useAccountTypes,
} from "@/lib/accounts/use-account-list";
import {
  ACCOUNT_TYPE_VALUES,
  type Account,
  type AccountListFilters,
  type AccountTypeValue,
} from "@/lib/accounts/types";

import { AccountFormDialog } from "./account-form-dialog";
import { AccountsListSkeleton } from "./accounts-list-skeleton";
import { AccountsTable } from "./accounts-table";
import { ConfirmActionDialog, type AccountLifecycleAction } from "./confirm-action-dialog";

/**
 * Chart of accounts screen.
 *
 * Composes the filter bar, the responsive account list and its pagination, and
 * hosts the create/edit and lifecycle dialogs. All reads go through
 * `useAccountList` / `useAccountTypes`; every write goes through the dialogs and
 * refreshes the list by bumping a reload token so the list always shows
 * authoritative backend state rather than a local guess.
 *
 * Search input is debounced into the query so typing does not fire a request per
 * keystroke. Filters and the current page live in one state object, so a filter
 * change resets the page to 1 in the same update (no reset effect needed).
 */

type AccountQuery = {
  search: string;
  account_type: AccountTypeValue | "";
  is_active: "" | "active" | "inactive";
  page: number;
};

const INITIAL_QUERY: AccountQuery = {
  search: "",
  account_type: "",
  is_active: "",
  page: 1,
};

const SEARCH_DEBOUNCE_MS = 300;

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function typeLabel(value: AccountTypeValue): string {
  return value.charAt(0) + value.slice(1).toLowerCase();
}

type Editor =
  | { mode: "create" }
  | { mode: "edit"; account: Account }
  | null;

export function AccountsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounts.view");
  const canCreate = can("accounts.create");
  const canUpdate = can("accounts.update");
  const canActivate = can("accounts.activate");
  const canDeactivate = can("accounts.deactivate");
  const canDelete = can("accounts.delete");
  const canWrite = canCreate || canUpdate;

  const [query, setQuery] = useState<AccountQuery>(INITIAL_QUERY);
  const [searchInput, setSearchInput] = useState("");
  const [reloadToken, setReloadToken] = useState(0);
  const [editor, setEditor] = useState<Editor>(null);
  const [action, setAction] = useState<AccountLifecycleAction | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery((current) =>
        current.search === searchInput
          ? current
          : { ...current, search: searchInput, page: 1 },
      );
    }, SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const filters = useMemo<AccountListFilters>(
    () => ({
      search: query.search,
      account_type: query.account_type,
      is_active: query.is_active,
    }),
    [query.search, query.account_type, query.is_active],
  );

  const state = useAccountList({
    companyId,
    enabled: canView,
    filters,
    page: query.page,
    perPage: ACCOUNT_LIST_PER_PAGE,
    reloadToken,
  });
  const typesState = useAccountTypes(companyId, canView);

  const reload = () => setReloadToken((token) => token + 1);

  const isFiltered =
    query.search.trim() !== "" ||
    query.account_type !== "" ||
    query.is_active !== "";

  const onSearchChange = (value: string) => {
    setSearchInput(value);
  };

  const onClearFilters = () => {
    setSearchInput("");
    setQuery(INITIAL_QUERY);
  };

  const header = (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">
          Chart of accounts
        </h1>
        <p className="text-sm text-muted-foreground">
          The accounts every journal entry is posted to for the active company.
        </p>
      </div>
      {canCreate ? (
        <Button onClick={() => setEditor({ mode: "create" })}>New account</Button>
      ) : null}
    </div>
  );

  const filterBar = (
    <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end">
      <div className="flex min-w-56 flex-1 flex-col gap-1.5">
        <label htmlFor="accounts-search" className="text-sm font-medium text-foreground">
          Search
        </label>
        <Input
          id="accounts-search"
          type="search"
          value={searchInput}
          onChange={(event) => onSearchChange(event.target.value)}
          placeholder="Search by code or name"
          autoComplete="off"
          disabled={!activeCompany}
        />
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="accounts-type" className="text-sm font-medium text-foreground">
          Type
        </label>
        <select
          id="accounts-type"
          className={selectClasses}
          value={query.account_type}
          onChange={(event) =>
            setQuery((current) => ({
              ...current,
              account_type: event.target.value as AccountTypeValue | "",
              page: 1,
            }))
          }
          disabled={!activeCompany}
        >
          <option value="">All types</option>
          {ACCOUNT_TYPE_VALUES.map((value) => (
            <option key={value} value={value}>
              {typeLabel(value)}
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="accounts-status" className="text-sm font-medium text-foreground">
          Status
        </label>
        <select
          id="accounts-status"
          className={selectClasses}
          value={query.is_active}
          onChange={(event) =>
            setQuery((current) => ({
              ...current,
              is_active: event.target.value as AccountQuery["is_active"],
              page: 1,
            }))
          }
          disabled={!activeCompany}
        >
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      {isFiltered ? (
        <Button variant="ghost" onClick={onClearFilters}>
          Clear
        </Button>
      ) : null}
    </div>
  );

  const resultsSummary =
    state.status === "ready" && state.items.length > 0 ? (
      <p className="text-sm text-muted-foreground">
        Showing {state.items.length} account{state.items.length === 1 ? "" : "s"} on page{" "}
        {query.page}
        {state.hasMore ? "; more may exist on later pages." : "."}
      </p>
    ) : null;

  const pagination =
    state.status === "ready" ? (
      <div className="flex items-center justify-between gap-3">
        <Button
          variant="secondary"
          disabled={query.page <= 1}
          onClick={() =>
            setQuery((current) => ({ ...current, page: Math.max(1, current.page - 1) }))
          }
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
    ) : null;

  const body = (() => {
    if (!activeCompany) {
      return (
        <EmptyState
          title="No company selected"
          description="Choose a company to view its chart of accounts."
        />
      );
    }

    if (!canView) {
      return (
        <EmptyState
          title="Chart of accounts unavailable"
          description="Your role does not include access to the chart of accounts."
        />
      );
    }

    if (state.status === "loading") {
      return <AccountsListSkeleton />;
    }

    if (state.status === "unavailable") {
      return <EmptyState title="Chart of accounts unavailable" description={state.message} />;
    }

    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load the chart of accounts"
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
            title="No accounts on this page"
            description="This page is empty. Return to the previous page to see the remaining accounts."
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

      if (isFiltered) {
        return (
          <EmptyState
            title="No accounts match"
            description="No accounts match the current search and filters."
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
          title="No accounts yet"
          description="Create the first account to start building this company's chart of accounts."
          action={
            canCreate ? (
              <Button onClick={() => setEditor({ mode: "create" })}>New account</Button>
            ) : undefined
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <AccountsTable
          items={state.items}
          canUpdate={canUpdate}
          canActivate={canActivate}
          canDeactivate={canDeactivate}
          canDelete={canDelete}
          onEdit={(account) => setEditor({ mode: "edit", account })}
          onAction={(nextAction) => setAction(nextAction)}
        />
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      {header}
      {filterBar}

      {canWrite && !canCreate ? (
        <Badge variant="warning">
          You can edit existing accounts but not create new ones.
        </Badge>
      ) : null}

      <div className="flex flex-col gap-4">
        {resultsSummary}
        {body}
        {pagination}
      </div>

      <AccountFormDialog
        key={editor?.mode === "edit" ? `edit-${editor.account.id}` : "create"}
        editor={editor}
        onClose={() => setEditor(null)}
        onSaved={reload}
        types={typesState.status === "ready" ? typesState.data : []}
      />

      <ConfirmActionDialog
        action={action}
        onClose={() => setAction(null)}
        onDone={reload}
      />
    </div>
  );
}