"use client";

import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { ApiError } from "@/lib/api/errors";
import {
  activateFinancialDimension,
  activateFinancialDimensionValue,
  deactivateFinancialDimension,
  deactivateFinancialDimensionValue,
  deleteFinancialDimensionValue,
} from "@/lib/dimensions/api";
import type { FinancialDimensionValue } from "@/lib/dimensions/types";
import { useFinancialDimension } from "@/lib/dimensions/use-dimension";
import {
  FINANCIAL_DIMENSION_VALUES_PER_PAGE,
  useFinancialDimensionValues,
} from "@/lib/dimensions/use-dimension-values";

import { DimensionFormDialog } from "./dimension-form-dialog";
import { DimensionTypeBadge } from "./dimension-type-badge";
import { DimensionValueFormDialog } from "./dimension-value-form-dialog";

/**
 * Financial-dimension detail.
 *
 * Shows the dimension's identity and lifecycle, then its values — the members a
 * budget line may actually be labelled with. A value has full CRUD here; both the
 * dimension and each value carry an activate/deactivate transition, and a
 * rejection (for example deleting a value a line still references) is shown
 * verbatim. There is no delete for the dimension itself: that lives on the list,
 * where a successful delete can navigate away.
 */

type ActiveFilter = "all" | "active" | "inactive";

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export function DimensionDetailView({ dimensionId }: { dimensionId: number }) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.dimensions.view");
  const canCreate = can("accounting.dimensions.create");
  const canUpdate = can("accounting.dimensions.update");
  const canDelete = can("accounting.dimensions.delete");

  const [reloadToken, setReloadToken] = useState(0);
  const [editOpen, setEditOpen] = useState(false);
  const [dimensionPending, setDimensionPending] = useState(false);

  const [valueSearch, setValueSearch] = useState("");
  const [valueAppliedSearch, setValueAppliedSearch] = useState("");
  const [valueActiveFilter, setValueActiveFilter] = useState<ActiveFilter>("all");
  const [valuePage, setValuePage] = useState(1);

  const [valueFormOpen, setValueFormOpen] = useState(false);
  const [editingValue, setEditingValue] = useState<FinancialDimensionValue | null>(null);
  const [deletingValue, setDeletingValue] = useState<FinancialDimensionValue | null>(null);
  const [valuePendingId, setValuePendingId] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const detail = useFinancialDimension({ id: dimensionId, companyId, enabled: canView, reloadToken });

  const valuesState = useFinancialDimensionValues({
    dimensionId,
    companyId,
    enabled: canView,
    filters: {
      is_active: valueActiveFilter === "all" ? undefined : valueActiveFilter === "active",
      search: valueAppliedSearch.trim() || undefined,
    },
    page: valuePage,
    perPage: FINANCIAL_DIMENSION_VALUES_PER_PAGE,
    reloadToken,
  });

  const reload = () => setReloadToken((token) => token + 1);
  const isValueFiltered = valueAppliedSearch.trim() !== "" || valueActiveFilter !== "all";

  const backLink = (
    <Link href="/dimensions" className="text-sm font-medium text-primary hover:underline">
      ← Dimensions
    </Link>
  );

  const runDimensionTransition = async (isActive: boolean) => {
    if (dimensionPending) return;
    setDimensionPending(true);
    setActionError(null);
    try {
      if (isActive) {
        await deactivateFinancialDimension(dimensionId);
        toast.success("The dimension has been deactivated.");
      } else {
        await activateFinancialDimension(dimensionId);
        toast.success("The dimension has been activated.");
      }
      reload();
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : "We could not update this dimension.");
    } finally {
      setDimensionPending(false);
    }
  };

  const openCreateValue = () => {
    setEditingValue(null);
    setValueFormOpen(true);
  };

  const openEditValue = (value: FinancialDimensionValue) => {
    setEditingValue(value);
    setValueFormOpen(true);
  };

  const onValueSaved = () => {
    setValueFormOpen(false);
    setEditingValue(null);
    reload();
  };

  const runValueTransition = async (value: FinancialDimensionValue) => {
    if (valuePendingId !== null) return;
    setValuePendingId(value.id);
    setActionError(null);
    try {
      if (value.is_active) {
        await deactivateFinancialDimensionValue(dimensionId, value.id);
        toast.success(`${value.code} has been deactivated.`);
      } else {
        await activateFinancialDimensionValue(dimensionId, value.id);
        toast.success(`${value.code} has been activated.`);
      }
      reload();
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : "We could not update this value.");
    } finally {
      setValuePendingId(null);
    }
  };

  const confirmDeleteValue = async () => {
    if (!deletingValue || valuePendingId !== null) return;
    setValuePendingId(deletingValue.id);
    setActionError(null);
    try {
      await deleteFinancialDimensionValue(dimensionId, deletingValue.id);
      toast.success(`${deletingValue.code} has been deleted.`);
      setDeletingValue(null);
      reload();
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : "We could not delete this value.");
    } finally {
      setValuePendingId(null);
    }
  };

  if (!activeCompany) {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState title="No company selected" description="Choose a company to view this dimension." />
      </div>
    );
  }

  if (!canView) {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState
          title="Dimensions unavailable"
          description="Your role does not include access to dimensions."
        />
      </div>
    );
  }

  if (detail.status === "loading") {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <LoadingState label="Loading dimension…" />
      </div>
    );
  }

  if (detail.status === "notfound") {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState
          title="Dimension not found"
          description="This dimension does not exist or is not in this company."
        />
      </div>
    );
  }

  if (detail.status === "unavailable") {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <EmptyState title="Dimension unavailable" description={detail.message} />
      </div>
    );
  }

  if (detail.status === "error" || !detail.dimension) {
    return (
      <div className="flex flex-col gap-6">
        {backLink}
        <ErrorState
          title="Could not load dimension"
          message={detail.message ?? "The dimension is unavailable."}
          requestId={detail.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      </div>
    );
  }

  const dimension = detail.dimension;

  const valuesBody = (() => {
    if (valuesState.status === "loading") {
      return <p className="text-sm text-muted-foreground">Loading values…</p>;
    }
    if (valuesState.status === "unavailable") {
      return <EmptyState title="Values unavailable" description={valuesState.message} />;
    }
    if (valuesState.status === "error") {
      return (
        <ErrorState
          title="Could not load values"
          message={valuesState.message}
          requestId={valuesState.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      );
    }
    if (valuesState.items.length === 0) {
      return (
        <EmptyState
          title={isValueFiltered ? "No matching values" : "No values yet"}
          description={
            isValueFiltered
              ? "No values match the current filters."
              : "Add a value so this dimension can label budget lines."
          }
          action={
            canCreate && !isValueFiltered ? (
              <Button type="button" onClick={openCreateValue}>
                New value
              </Button>
            ) : undefined
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <table className="w-full min-w-[40rem] border-collapse text-sm">
          <caption className="sr-only">Dimension values</caption>
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
              <th scope="col" className="px-3 py-2 font-medium">Value</th>
              <th scope="col" className="px-3 py-2 font-medium">Status</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Actions</th>
            </tr>
          </thead>
          <tbody>
            {valuesState.items.map((value) => (
              <tr key={value.id} className="border-b border-border/70 align-top">
                <td className="px-3 py-3">
                  <div className="font-medium text-foreground">{value.code}</div>
                  <div className="text-muted-foreground">{value.name}</div>
                </td>
                <td className="px-3 py-3">
                  {value.is_active ? (
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
                        onClick={() => openEditValue(value)}
                      >
                        Edit
                      </button>
                    ) : null}
                    {canUpdate ? (
                      <button
                        type="button"
                        className="text-sm font-medium text-primary hover:underline disabled:text-muted-foreground"
                        disabled={valuePendingId === value.id}
                        onClick={() => runValueTransition(value)}
                      >
                        {value.is_active ? "Deactivate" : "Activate"}
                      </button>
                    ) : null}
                    {canDelete ? (
                      <button
                        type="button"
                        className="text-sm font-medium text-danger hover:underline"
                        onClick={() => {
                          setDeletingValue(value);
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
      {backLink}

      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="flex flex-col gap-2">
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-semibold text-foreground">{dimension.code}</h1>
            <DimensionTypeBadge type={dimension.type} label={dimension.type_label} />
            {dimension.is_active ? (
              <Badge variant="success">Active</Badge>
            ) : (
              <Badge variant="neutral">Inactive</Badge>
            )}
          </div>
          <p className="text-sm text-muted-foreground">{dimension.name}</p>
        </div>

        <div className="flex flex-wrap gap-2">
          {canUpdate ? (
            <Button variant="secondary" onClick={() => setEditOpen(true)}>
              Edit
            </Button>
          ) : null}
          {canUpdate ? (
            <Button
              variant="ghost"
              loading={dimensionPending}
              onClick={() => runDimensionTransition(dimension.is_active)}
            >
              {dimension.is_active ? "Deactivate" : "Activate"}
            </Button>
          ) : null}
        </div>
      </div>

      {actionError ? (
        <p
          role="alert"
          className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
        >
          {actionError}
        </p>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Values</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col gap-4">
            <form
              className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end"
              onSubmit={(event) => {
                event.preventDefault();
                setValueAppliedSearch(valueSearch);
                setValuePage(1);
              }}
            >
              <FormField label="Search" htmlFor="dimension-value-search">
                <Input
                  id="dimension-value-search"
                  value={valueSearch}
                  placeholder="Code or name"
                  onChange={(event) => setValueSearch(event.target.value)}
                />
              </FormField>

              <FormField label="Status" htmlFor="dimension-value-active">
                <select
                  id="dimension-value-active"
                  className={selectClasses}
                  value={valueActiveFilter}
                  onChange={(event) => {
                    setValueActiveFilter(event.target.value as ActiveFilter);
                    setValuePage(1);
                  }}
                >
                  <option value="all">All</option>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </FormField>

              <div className="flex gap-2">
                <Button type="submit">Apply</Button>
                {isValueFiltered ? (
                  <Button
                    type="button"
                    variant="ghost"
                    onClick={() => {
                      setValueSearch("");
                      setValueAppliedSearch("");
                      setValueActiveFilter("all");
                      setValuePage(1);
                    }}
                  >
                    Clear
                  </Button>
                ) : null}
              </div>

              <div className="sm:ml-auto">
                {canCreate ? (
                  <Button type="button" onClick={openCreateValue}>
                    New value
                  </Button>
                ) : null}
              </div>
            </form>

            {valuesBody}

            {valuesState.status === "ready" && (valuesState.items.length > 0 || valuePage > 1) ? (
              <div className="flex items-center justify-between gap-3">
                <Button
                  variant="secondary"
                  disabled={valuePage <= 1}
                  onClick={() => setValuePage((current) => Math.max(1, current - 1))}
                >
                  Previous
                </Button>
                <span className="text-sm text-muted-foreground">Page {valuePage}</span>
                <Button
                  variant="secondary"
                  disabled={!valuesState.hasMore}
                  onClick={() => setValuePage((current) => current + 1)}
                >
                  Next
                </Button>
              </div>
            ) : null}
          </div>
        </CardContent>
      </Card>

      <DimensionFormDialog
        open={editOpen}
        dimension={dimension}
        onClose={() => setEditOpen(false)}
        onSaved={() => {
          setEditOpen(false);
          reload();
        }}
      />

      <DimensionValueFormDialog
        open={valueFormOpen}
        dimensionId={dimensionId}
        value={editingValue}
        onClose={() => {
          setValueFormOpen(false);
          setEditingValue(null);
        }}
        onSaved={onValueSaved}
      />

      <Dialog
        open={Boolean(deletingValue)}
        title="Delete value"
        onClose={() => setDeletingValue(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeletingValue(null)} disabled={valuePendingId !== null}>
              Cancel
            </Button>
            <Button variant="danger" onClick={confirmDeleteValue} loading={valuePendingId === deletingValue?.id}>
              Delete value
            </Button>
          </>
        }
      >
        <p className="text-sm text-muted-foreground">
          Delete <span className="font-medium text-foreground">{deletingValue?.code}</span> — {deletingValue?.name}?
          A value referenced by a journal or a budget line cannot be deleted; the server will refuse and the reason
          will be shown.
        </p>
      </Dialog>
    </div>
  );
}
