"use client";

import { useEffect, useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Input } from "@/components/ui/field";
import { JOURNAL_STATUS_VALUES, type Journal, type JournalStatusValue } from "@/lib/journals/types";
import { JOURNAL_LIST_PER_PAGE, useJournalList } from "@/lib/journals/use-journal-list";

import { JournalConfirmDialog, type JournalAction } from "./journal-confirm-dialog";
import { JournalDetailDialog } from "./journal-detail-dialog";
import { JournalFormDialog } from "./journal-form-dialog";
import { JournalsListSkeleton } from "./journals-list-skeleton";
import { JournalsTable } from "./journals-table";

/**
 * Journal entry screen.
 *
 * Composes the filter bar, the responsive journal list and its pagination, and
 * hosts the create/edit dialog, the read-only detail dialog and the post/delete
 * confirmations. Reads go through `useJournalList`; every write goes through a
 * dialog and refreshes the list by bumping a reload token so the list always
 * reflects authoritative backend state. Search is debounced so typing does not
 * fire a request per keystroke, and a filter change resets the page to 1 in the
 * same state update.
 */

type JournalQuery = {
  search: string;
  status: JournalStatusValue | "";
  from: string;
  to: string;
  page: number;
};

const INITIAL_QUERY: JournalQuery = { search: "", status: "", from: "", to: "", page: 1 };

const SEARCH_DEBOUNCE_MS = 300;

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type Editor = { mode: "create" } | { mode: "edit"; journal: Journal } | null;

function statusLabel(value: JournalStatusValue): string {
  return value === "POSTED" ? "Posted" : "Draft";
}

export function JournalsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("journals.view");
  const canCreate = can("journals.create");
  const canUpdate = can("journals.update");
  const canDelete = can("journals.delete");
  const canPost = can("journals.post");

  const [query, setQuery] = useState<JournalQuery>(INITIAL_QUERY);
  const [searchInput, setSearchInput] = useState("");
  const [reloadToken, setReloadToken] = useState(0);
  const [editor, setEditor] = useState<Editor>(null);
  const [detail, setDetail] = useState<Journal | null>(null);
  const [action, setAction] = useState<JournalAction | null>(null);

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

  const state = useJournalList({
    companyId,
    enabled: canView,
    filters: {
      search: query.search,
      status: query.status,
      from: query.from,
      to: query.to,
    },
    page: query.page,
    perPage: JOURNAL_LIST_PER_PAGE,
    reloadToken,
  });

  const reload = () => setReloadToken((token) => token + 1);

  const hasFilters =
    query.search.trim() !== "" ||
    query.status !== "" ||
    query.from !== "" ||
    query.to !== "";

  const handleAction = (next: JournalAction) => {
    setDetail(null);
    setAction(next);
  };

  const header = (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex flex-col gap-1">
        <h1 className="text-lg font-semibold text-foreground">Journals</h1>
        <p className="text-sm text-muted-foreground">
          Draft, review and post accounting entries for the active company.
        </p>
      </div>
      {canCreate ? (
        <Button onClick={() => setEditor({ mode: "create" })}>New journal</Button>
      ) : null}
    </div>
  );

  return (
    <div className="flex flex-col gap-6">
      {header}

      <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end">
        <div className="flex min-w-56 flex-1 flex-col gap-1.5">
          <label htmlFor="journals-search" className="text-sm font-medium text-foreground">
            Search
          </label>
          <Input
            id="journals-search"
            type="search"
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            placeholder="Search by description, reference or number"
            autoComplete="off"
            disabled={!activeCompany}
          />
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="journals-status" className="text-sm font-medium text-foreground">
            Status
          </label>
          <select
            id="journals-status"
            className={selectClasses}
            value={query.status}
            onChange={(event) =>
              setQuery((current) => ({
                ...current,
                status: event.target.value as JournalStatusValue | "",
                page: 1,
              }))
            }
            disabled={!activeCompany}
          >
            <option value="">All statuses</option>
            {JOURNAL_STATUS_VALUES.map((value) => (
              <option key={value} value={value}>
                {statusLabel(value)}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="journals-from" className="text-sm font-medium text-foreground">
            From
          </label>
          <Input
            id="journals-from"
            type="date"
            value={query.from}
            onChange={(event) =>
              setQuery((current) => ({ ...current, from: event.target.value, page: 1 }))
            }
            disabled={!activeCompany}
          />
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="journals-to" className="text-sm font-medium text-foreground">
            To
          </label>
          <Input
            id="journals-to"
            type="date"
            value={query.to}
            onChange={(event) =>
              setQuery((current) => ({ ...current, to: event.target.value, page: 1 }))
            }
            disabled={!activeCompany}
          />
        </div>

        {hasFilters ? (
          <Button
            variant="secondary"
            onClick={() => {
              setSearchInput("");
              setQuery(INITIAL_QUERY);
            }}
          >
            Clear
          </Button>
        ) : null}
      </div>

      {state.status === "ready" ? (
        <p className="text-sm text-muted-foreground">
          {state.items.length} journal{state.items.length === 1 ? "" : "s"} on page {query.page}
          {state.hasMore ? " · more may follow" : ""}
        </p>
      ) : null}

      <JournalListBody
        state={state}
        activeCompany={Boolean(activeCompany)}
        canView={canView}
        canCreate={canCreate}
        hasFilters={hasFilters}
        page={query.page}
        onReload={reload}
        onNew={() => setEditor({ mode: "create" })}
        onPreviousPage={() => setQuery((current) => ({ ...current, page: Math.max(1, current.page - 1) }))}
        canUpdate={canUpdate}
        canDelete={canDelete}
        canPost={canPost}
        onView={(journal) => setDetail(journal)}
        onEdit={(journal) => setEditor({ mode: "edit", journal })}
        onAction={handleAction}
      />

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

      <JournalFormDialog
        key={editor?.mode === "edit" ? `edit-${editor.journal.id}` : "create"}
        editor={editor}
        companyId={companyId}
        onClose={() => setEditor(null)}
        onSaved={reload}
      />

      <JournalDetailDialog
        journal={detail}
        canUpdate={canUpdate}
        canDelete={canDelete}
        canPost={canPost}
        onClose={() => setDetail(null)}
        onEdit={(journal) => {
          setDetail(null);
          setEditor({ mode: "edit", journal });
        }}
        onAction={handleAction}
      />

      <JournalConfirmDialog action={action} onClose={() => setAction(null)} onDone={reload} />
    </div>
  );
}

type JournalListBodyProps = {
  state: ReturnType<typeof useJournalList>;
  activeCompany: boolean;
  canView: boolean;
  canCreate: boolean;
  hasFilters: boolean;
  page: number;
  onReload: () => void;
  onNew: () => void;
  onPreviousPage: () => void;
} & Omit<
  Parameters<typeof JournalsTable>[0],
  "items"
>;

function JournalListBody({
  state,
  activeCompany,
  canView,
  canCreate,
  hasFilters,
  page,
  onReload,
  onNew,
  onPreviousPage,
  ...tableProps
}: JournalListBodyProps) {
  if (!activeCompany) {
    return (
      <EmptyState
        title="No company selected"
        description="Choose a company to see its journals."
      />
    );
  }

  if (!canView) {
    return (
      <EmptyState
        title="Journals unavailable"
        description="Your role does not include access to journals."
      />
    );
  }

  if (state.status === "loading") {
    return <JournalsListSkeleton />;
  }

  if (state.status === "unavailable") {
    return <EmptyState title="Journals unavailable" description={state.message} />;
  }

  if (state.status === "error") {
    return (
      <ErrorState
        title="Could not load journals"
        message={state.message}
        requestId={state.requestId}
        action={
          <Button variant="secondary" onClick={onReload}>
            Try again
          </Button>
        }
      />
    );
  }

  if (state.items.length === 0) {
    if (page > 1) {
      return (
        <EmptyState
          title="No journals on this page"
          description="This page is empty."
          action={
            <Button variant="secondary" onClick={onPreviousPage}>
              Previous page
            </Button>
          }
        />
      );
    }

    if (hasFilters) {
      return (
        <EmptyState
          title="No journals match"
          description="Try a different search or date range."
        />
      );
    }

    return (
      <EmptyState
        title="No journals yet"
        description="Create the first draft journal to start recording entries."
        action={canCreate ? <Button onClick={onNew}>New journal</Button> : undefined}
      />
    );
  }

  return (
    <div className="overflow-x-auto">
      <JournalsTable items={state.items} {...tableProps} />
    </div>
  );
}
