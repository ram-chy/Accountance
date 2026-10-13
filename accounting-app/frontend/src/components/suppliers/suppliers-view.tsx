"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { SupplierFormDialog } from "@/components/suppliers/supplier-form-dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { ApiError } from "@/lib/api/errors";
import { deactivateSupplier } from "@/lib/suppliers/api";
import type { Supplier, SupplierListQuery } from "@/lib/suppliers/types";
import { SUPPLIER_LIST_PER_PAGE, useSuppliers } from "@/lib/suppliers/use-suppliers";

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type StatusFilter = "all" | "active" | "inactive";

type Filters = {
  search: string;
  status: StatusFilter;
};

const DEFAULT_FILTERS: Filters = { search: "", status: "all" };

function toQuery(filters: Filters, page: number): SupplierListQuery {
  return {
    search: filters.search.trim() || undefined,
    is_active: filters.status === "all" ? undefined : filters.status === "active",
    page,
    per_page: SUPPLIER_LIST_PER_PAGE,
  };
}

export function SuppliersView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("suppliers.view");
  const canCreate = can("suppliers.create");
  const canUpdate = can("suppliers.update");
  const canDeactivate = can("suppliers.deactivate");

  const [draft, setDraft] = useState<Filters>(DEFAULT_FILTERS);
  const [applied, setApplied] = useState<Filters>(DEFAULT_FILTERS);
  const [page, setPage] = useState(1);
  const [reloadToken, setReloadToken] = useState(0);

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Supplier | null>(null);
  const [pendingDeactivate, setPendingDeactivate] = useState<Supplier | null>(null);
  const [deactivating, setDeactivating] = useState(false);
  const [deactivateError, setDeactivateError] = useState<string | null>(null);

  const state = useSuppliers({ companyId, enabled: canView, query: toQuery(applied, page), reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
    setPage(1);
  };

  const clear = () => {
    setDraft(DEFAULT_FILTERS);
    setApplied(DEFAULT_FILTERS);
    setPage(1);
  };

  const afterSave = () => {
    setFormOpen(false);
    setEditing(null);
    setReloadToken((token) => token + 1);
  };

  const confirmDeactivate = async () => {
    if (!pendingDeactivate || deactivating) return;
    setDeactivating(true);
    setDeactivateError(null);
    try {
      await deactivateSupplier(pendingDeactivate.id);
      setPendingDeactivate(null);
      setReloadToken((token) => token + 1);
    } catch (error) {
      setDeactivateError(
        error instanceof ApiError
          ? error.message
          : "We could not deactivate this supplier. Please try again.",
      );
    } finally {
      setDeactivating(false);
    }
  };

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its suppliers." />;
    }
    if (!canView) {
      return (
        <EmptyState title="Suppliers unavailable" description="Your role does not include access to suppliers." />
      );
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading suppliers…" />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Suppliers unavailable" description={state.message} />;
    }
    if (state.status === "error") {
      return (
        <ErrorState
          message={state.message}
          requestId={state.requestId}
          action={
            <Button type="button" onClick={() => setReloadToken((token) => token + 1)}>
              Retry
            </Button>
          }
        />
      );
    }
    if (state.items.length === 0) {
      return page > 1 ? (
        <EmptyState title="No suppliers on this page" description="Go back to the previous page." />
      ) : (
        <EmptyState title="No suppliers yet" description="Create your first supplier to start recording bills." />
      );
    }

    return (
      <div className="flex flex-col gap-4">
        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead className="bg-surface-muted">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Code</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Name</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Contact</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Tax ID</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Status</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {state.items.map((supplier) => (
                <tr key={supplier.id}>
                  <td className="px-3 py-2 font-mono text-xs">{supplier.supplier_code}</td>
                  <td className="px-3 py-2">
                    <Link className="font-medium text-foreground hover:underline" href={`/suppliers/${supplier.id}`}>
                      {supplier.name}
                    </Link>
                  </td>
                  <td className="px-3 py-2 text-muted-foreground">
                    {supplier.email ?? supplier.phone ?? "—"}
                  </td>
                  <td className="px-3 py-2 text-muted-foreground">{supplier.tax_identifier ?? "—"}</td>
                  <td className="px-3 py-2">
                    <Badge variant={supplier.is_active ? "success" : "neutral"}>
                      {supplier.is_active ? "Active" : "Inactive"}
                    </Badge>
                  </td>
                  <td className="px-3 py-2">
                    <div className="flex justify-end gap-2">
                      {canUpdate ? (
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => {
                            setEditing(supplier);
                            setFormOpen(true);
                          }}
                        >
                          Edit
                        </Button>
                      ) : null}
                      {canDeactivate && supplier.is_active ? (
                        <Button
                          size="sm"
                          variant="danger"
                          onClick={() => {
                            setDeactivateError(null);
                            setPendingDeactivate(supplier);
                          }}
                        >
                          Deactivate
                        </Button>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="flex items-center justify-between">
          <Button
            type="button"
            variant="secondary"
            size="sm"
            disabled={page <= 1}
            onClick={() => setPage((current) => Math.max(1, current - 1))}
          >
            Previous
          </Button>
          <span className="text-sm text-muted-foreground">Page {page}</span>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            disabled={!state.hasMore}
            onClick={() => setPage((current) => current + 1)}
          >
            Next
          </Button>
        </div>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">Suppliers</h1>
          <p className="mt-1 text-sm text-muted-foreground">Suppliers you buy from and pay.</p>
        </div>
        {canCreate ? (
          <Button
            type="button"
            onClick={() => {
              setEditing(null);
              setFormOpen(true);
            }}
          >
            New supplier
          </Button>
        ) : null}
      </div>

      <form
        onSubmit={apply}
        className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      >
        <FormField label="Search" htmlFor="supplier-search">
          <Input
            id="supplier-search"
            value={draft.search}
            placeholder="Name, code, email…"
            onChange={(event) => setDraft((current) => ({ ...current, search: event.target.value }))}
          />
        </FormField>
        <FormField label="Status" htmlFor="supplier-status">
          <select
            id="supplier-status"
            className={selectClasses}
            value={draft.status}
            onChange={(event) =>
              setDraft((current) => ({ ...current, status: event.target.value as StatusFilter }))
            }
          >
            <option value="all">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </FormField>
        <div className="flex gap-2 pb-1.5">
          <Button type="submit">Apply</Button>
          <Button type="button" variant="secondary" onClick={clear}>
            Clear
          </Button>
        </div>
      </form>

      {body}

      {formOpen ? (
        <SupplierFormDialog
          open={formOpen}
          companyId={companyId}
          supplier={editing}
          onClose={() => {
            setFormOpen(false);
            setEditing(null);
          }}
          onSaved={afterSave}
        />
      ) : null}

      <Dialog
        open={pendingDeactivate !== null}
        title="Deactivate supplier"
        description={
          pendingDeactivate
            ? `Deactivate ${pendingDeactivate.name}? The supplier stays on past documents, but cannot be used on new bills.`
            : undefined
        }
        onClose={() => {
          if (!deactivating) {
            setPendingDeactivate(null);
            setDeactivateError(null);
          }
        }}
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setPendingDeactivate(null);
                setDeactivateError(null);
              }}
              disabled={deactivating}
            >
              Cancel
            </Button>
            <Button type="button" variant="danger" loading={deactivating} onClick={confirmDeactivate}>
              Deactivate
            </Button>
          </>
        }
      >
        {deactivateError ? (
          <p role="alert" className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground">
            {deactivateError}
          </p>
        ) : null}
      </Dialog>
    </div>
  );
}
