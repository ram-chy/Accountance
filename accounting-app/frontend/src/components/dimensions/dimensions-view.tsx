"use client";

import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { ApiError } from "@/lib/api/errors";
import {
  activateFinancialDimension,
  deactivateFinancialDimension,
  deleteFinancialDimension,
} from "@/lib/dimensions/api";
import {
  FINANCIAL_DIMENSION_TYPE_LABELS,
  FINANCIAL_DIMENSION_TYPE_VALUES,
  type FinancialDimension,
  type FinancialDimensionTypeValue,
} from "@/lib/dimensions/types";
import {
  FINANCIAL_DIMENSIONS_PER_PAGE,
  useFinancialDimensions,
} from "@/lib/dimensions/use-dimensions";

import { DimensionFormDialog } from "./dimension-form-dialog";
import { DimensionTypeBadge } from "./dimension-type-badge";

/**
 * Financial-dimension management.
 *
 * Dimensions are the analytical axes the ledger may be cut along (cost centre,
 * project, department, location). The list supports search plus a type and
 * active/inactive filter; create and edit use a dialog form; delete and the
 * activate/deactivate transitions are confirmed. Server rules still govern what
 * is allowed — for example a dimension whose values are referenced by a journal
 * or a budget line cannot be deleted — and any rejection is shown verbatim.
 */

type ActiveFilter = "all" | "active" | "inactive";
type TypeFilter = "all" | FinancialDimensionTypeValue;

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export function DimensionsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.dimensions.view");
  const canCreate = can("accounting.dimensions.create");
  const canUpdate = can("accounting.dimensions.update");
  const canDelete = can("accounting.dimensions.delete");

  const [search, setSearch] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");
  const [typeFilter, setTypeFilter] = useState<TypeFilter>("all");
  const [activeFilter, setActiveFilter] = useState<ActiveFilter>("all");
  const [page, setPage] = useState(1);
  const [reloadToken, setReloadToken] = useState(0);

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<FinancialDimension | null>(null);
  const [deleting, setDeleting] = useState<FinancialDimension | null>(null);
  const [pendingId, setPendingId] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const state = useFinancialDimensions({
    companyId,
    enabled: canView,
    filters: {
      type: typeFilter === "all" ? undefined : typeFilter,
      is_active: activeFilter === "all" ? undefined : activeFilter === "active",
      search: appliedSearch.trim() || undefined,
    },
    page,
    perPage: FINANCIAL_DIMENSIONS_PER_PAGE,
    reloadToken,
  });

  const reload = () => setReloadToken((token) => token + 1);
  const isFiltered = appliedSearch.trim() !== "" || typeFilter !== "all" || activeFilter !== "all";

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const openEdit = (dimension: FinancialDimension) => {
    setEditing(dimension);
    setFormOpen(true);
  };

  const onSaved = () => {
    setFormOpen(false);
    setEditing(null);
    reload();
  };

  const runTransition = async (dimension: FinancialDimension) => {
    if (pendingId !== null) return;
    setPendingId(dimension.id);
    setActionError(null);
    try {
      if (dimension.is_active) {
        await deactivateFinancialDimension(dimension.id);
        toast.success(`${dimension.code} has been deactivated.`);
      } else {
        await activateFinancialDimension(dimension.id);
        toast.success(`${dimension.code} has been activated.`);
      }
      reload();
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : "We could not update this dimension.");
    } finally {
      setPendingId(null);
    }
  };

  const confirmDelete = async () => {
    if (!deleting || pendingId !== null) return;
    setPendingId(deleting.id);
    setActionError(null);
    try {
      await deleteFinancialDimension(deleting.id);
      toast.success(`${deleting.code} has been deleted.`);
      setDeleting(null);
      reload();
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : "We could not delete this dimension.");
    } finally {
      setPendingId(null);
    }
  };

  const header = (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Dimensions</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Analytical axes — cost centres, projects, departments and locations — that budget lines may be labelled with.
        </p>
      </div>
      {canCreate ? (
        <Button type="button" onClick={openCreate}>
          New dimension
        </Button>
      ) : null}
    </div>
  );

  const filterBar = (
    <form
      className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      onSubmit={(event) => {
        event.preventDefault();
        setAppliedSearch(search);
        setPage(1);
      }}
    >
      <FormField label="Search" htmlFor="dimension-search">
        <Input
          id="dimension-search"
          value={search}
          placeholder="Code or name"
          onChange={(event) => setSearch(event.target.value)}
        />
      </FormField>

      <FormField label="Type" htmlFor="dimension-type-filter">
        <select
          id="dimension-type-filter"
          className={selectClasses}
          value={typeFilter}
          onChange={(event) => {
            setTypeFilter(event.target.value as TypeFilter);
            setPage(1);
          }}
        >
          <option value="all">All types</option>
          {FINANCIAL_DIMENSION_TYPE_VALUES.map((type) => (
            <option key={type} value={type}>
              {FINANCIAL_DIMENSION_TYPE_LABELS[type]}
            </option>
          ))}
        </select>
      </FormField>

      <FormField label="Status" htmlFor="dimension-active-filter">
        <select
          id="dimension-active-filter"
          className={selectClasses}
          value={activeFilter}
          onChange={(event) => {
            setActiveFilter(event.target.value as ActiveFilter);
            setPage(1);
          }}
        >
          <option value="all">All</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </FormField>

      <div className="flex gap-2">
        <Button type="submit">Apply</Button>
        {isFiltered ? (
          <Button
            type="button"
            variant="ghost"
            onClick={() => {
              setSearch("");
              setAppliedSearch("");
              setTypeFilter("all");
              setActiveFilter("all");
              setPage(1);
            }}
          >
            Clear
          </Button>
        ) : null}
      </div>
    </form>
  );

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its dimensions." />;
    }
    if (!canView) {
      return (
        <EmptyState
          title="Dimensions unavailable"
          description="Your role does not include access to dimensions."
        />
      );
    }
    if (state.status === "loading") {
      return <p className="text-sm text-muted-foreground">Loading dimensions…</p>;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Dimensions unavailable" description={state.message} />;
    }
    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load dimensions"
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
      return (
        <EmptyState
          title={isFiltered ? "No matching dimensions" : "No dimensions yet"}
          description={
            isFiltered
              ? "No dimensions match the current filters."
              : "Create a dimension before labelling budget lines."
          }
          action={
            canCreate && !isFiltered ? (
              <Button type="button" onClick={openCreate}>
                New dimension
              </Button>
            ) : undefined
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <table className="w-full min-w-[48rem] border-collapse text-sm">
          <caption className="sr-only">Financial dimensions</caption>
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
              <th scope="col" className="px-3 py-2 font-medium">Dimension</th>
              <th scope="col" className="px-3 py-2 font-medium">Type</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Values</th>
              <th scope="col" className="px-3 py-2 font-medium">Status</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Actions</th>
            </tr>
          </thead>
          <tbody>
            {state.items.map((dimension) => (
              <tr key={dimension.id} className="border-b border-border/70 align-top">
                <td className="px-3 py-3">
                  <Link href={`/dimensions/${dimension.id}`} className="font-medium text-primary hover:underline">
                    {dimension.code}
                  </Link>
                  <div className="text-muted-foreground">{dimension.name}</div>
                </td>
                <td className="px-3 py-3">
                  <DimensionTypeBadge type={dimension.type} label={dimension.type_label} />
                </td>
                <td className="px-3 py-3 text-right tabular-nums text-muted-foreground">
                  {dimension.values_count ?? "—"}
                </td>
                <td className="px-3 py-3">
                  {dimension.is_active ? (
                    <Badge variant="success">Active</Badge>
                  ) : (
                    <Badge variant="neutral">Inactive</Badge>
                  )}
                </td>
                <td className="px-3 py-3">
                  <div className="flex flex-wrap justify-end gap-3">
                    {canUpdate ? (
                      <button
                        type="button"
                        className="text-sm font-medium text-primary hover:underline"
                        onClick={() => openEdit(dimension)}
                      >
                        Edit
                      </button>
                    ) : null}
                    {canUpdate ? (
                      <button
                        type="button"
                        className="text-sm font-medium text-primary hover:underline disabled:text-muted-foreground"
                        disabled={pendingId === dimension.id}
                        onClick={() => runTransition(dimension)}
                      >
                        {dimension.is_active ? "Deactivate" : "Activate"}
                      </button>
                    ) : null}
                    {canDelete ? (
                      <button
                        type="button"
                        className="text-sm font-medium text-danger hover:underline"
                        onClick={() => {
                          setDeleting(dimension);
                          setActionError(null);
                        }}
                      >
                        Delete
                      </button>
                    ) : null}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      {header}
      {filterBar}

      {actionError ? (
        <p
          role="alert"
          className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
        >
          {actionError}
        </p>
      ) : null}

      <div className="flex flex-col gap-4">
        {body}

        {state.status === "ready" && (state.items.length > 0 || page > 1) ? (
          <div className="flex items-center justify-between gap-3">
            <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}>
              Previous
            </Button>
            <span className="text-sm text-muted-foreground">Page {page}</span>
            <Button variant="secondary" disabled={!state.hasMore} onClick={() => setPage((current) => current + 1)}>
              Next
            </Button>
          </div>
        ) : null}
      </div>

      <DimensionFormDialog
        open={formOpen}
        dimension={editing}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSaved={onSaved}
      />

      <Dialog
        open={Boolean(deleting)}
        title="Delete dimension"
        onClose={() => setDeleting(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeleting(null)} disabled={pendingId !== null}>
              Cancel
            </Button>
            <Button variant="danger" onClick={confirmDelete} loading={pendingId === deleting?.id}>
              Delete dimension
            </Button>
          </>
        }
      >
        <p className="text-sm text-muted-foreground">
          Delete <span className="font-medium text-foreground">{deleting?.code}</span> — {deleting?.name}? A
          dimension whose values are referenced by a journal or a budget line cannot be deleted; the server will
          refuse and the reason will be shown.
        </p>
      </Dialog>
    </div>
  );
}
