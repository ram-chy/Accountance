"use client";

import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { useAccountOptions } from "@/lib/accounts/use-account-options";
import { ApiError } from "@/lib/api/errors";
import {
  activateFixedAssetCategory,
  deactivateFixedAssetCategory,
  deleteFixedAssetCategory,
} from "@/lib/fixed-asset-categories/api";
import type { FixedAssetCategory } from "@/lib/fixed-asset-categories/types";
import {
  FIXED_ASSET_CATEGORIES_PER_PAGE,
  useFixedAssetCategories,
} from "@/lib/fixed-asset-categories/use-fixed-asset-categories";

import { FixedAssetCategoryFormDialog } from "./fixed-asset-category-form-dialog";

/**
 * Fixed-asset category management.
 *
 * Categories are the reusable templates an asset inherits (useful life,
 * depreciation method, and the accounting accounts). The list supports search
 * and an active/inactive filter; create and edit use a dialog form; delete and
 * the activate/deactivate transitions are confirmed inline. Server rules still
 * govern what is allowed (for example, a category with assets may not be
 * deletable) and any rejection is shown verbatim.
 */

type ActiveFilter = "all" | "active" | "inactive";

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function usefulLife(months: number): string {
  if (months % 12 === 0) {
    const years = months / 12;
    return `${years} ${years === 1 ? "year" : "years"}`;
  }
  return `${months} ${months === 1 ? "month" : "months"}`;
}

function accountLabel(code: string | undefined, name: string | undefined): string {
  if (!code && !name) return "—";
  return code ? `${code}` : (name ?? "—");
}

export function FixedAssetCategoriesView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canView = can("accounting.fixed_asset.view");
  const canCreate = can("accounting.fixed_asset.create");
  const canUpdate = can("accounting.fixed_asset.update");
  const canDelete = can("accounting.fixed_asset.delete");

  const [search, setSearch] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");
  const [activeFilter, setActiveFilter] = useState<ActiveFilter>("all");
  const [page, setPage] = useState(1);
  const [reloadToken, setReloadToken] = useState(0);

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<FixedAssetCategory | null>(null);
  const [deleting, setDeleting] = useState<FixedAssetCategory | null>(null);
  const [pendingId, setPendingId] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  // Resolve account codes for a friendly display without extra requests.
  const accountsState = useAccountOptions(companyId, canView);
  const accounts = accountsState.status === "ready" ? accountsState.items : [];
  const codeById = new Map(accounts.map((account) => [account.id, account.code]));

  const state = useFixedAssetCategories({
    companyId,
    enabled: canView,
    filters: {
      is_active: activeFilter === "all" ? undefined : activeFilter === "active",
      search: appliedSearch.trim() || undefined,
    },
    page,
    perPage: FIXED_ASSET_CATEGORIES_PER_PAGE,
    reloadToken,
  });

  const reload = () => setReloadToken((token) => token + 1);
  const isFiltered = appliedSearch.trim() !== "" || activeFilter !== "all";

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const openEdit = (category: FixedAssetCategory) => {
    setEditing(category);
    setFormOpen(true);
  };

  const onSaved = () => {
    setFormOpen(false);
    setEditing(null);
    reload();
  };

  const runTransition = async (category: FixedAssetCategory) => {
    if (pendingId !== null) return;
    setPendingId(category.id);
    setActionError(null);
    try {
      if (category.is_active) {
        await deactivateFixedAssetCategory(category.id);
      } else {
        await activateFixedAssetCategory(category.id);
      }
      reload();
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : "We could not update this category.");
    } finally {
      setPendingId(null);
    }
  };

  const confirmDelete = async () => {
    if (!deleting || pendingId !== null) return;
    setPendingId(deleting.id);
    setActionError(null);
    try {
      await deleteFixedAssetCategory(deleting.id);
      setDeleting(null);
      reload();
    } catch (error) {
      setActionError(error instanceof ApiError ? error.message : "We could not delete this category.");
    } finally {
      setPendingId(null);
    }
  };

  const header = (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Fixed asset categories</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Defaults that assets inherit: useful life, depreciation method, and accounts.
        </p>
      </div>
      {canCreate ? (
        <Button type="button" onClick={openCreate}>
          New category
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
      <FormField label="Search" htmlFor="fa-category-search">
        <Input
          id="fa-category-search"
          value={search}
          placeholder="Code or name"
          onChange={(event) => setSearch(event.target.value)}
        />
      </FormField>

      <FormField label="Status" htmlFor="fa-category-active">
        <select
          id="fa-category-active"
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
      return <EmptyState title="No company selected" description="Choose a company to view its categories." />;
    }
    if (!canView) {
      return (
        <EmptyState
          title="Categories unavailable"
          description="Your role does not include access to fixed assets."
        />
      );
    }
    if (state.status === "loading") {
      return <p className="text-sm text-muted-foreground">Loading categories…</p>;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Categories unavailable" description={state.message} />;
    }
    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load categories"
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
          title={isFiltered ? "No matching categories" : "No categories yet"}
          description={
            isFiltered
              ? "No fixed-asset categories match the current filters."
              : "Create a category before adding fixed assets."
          }
          action={
            canCreate && !isFiltered ? (
              <Button type="button" onClick={openCreate}>
                New category
              </Button>
            ) : undefined
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <table className="w-full min-w-[64rem] border-collapse text-sm">
          <caption className="sr-only">Fixed asset categories</caption>
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
              <th scope="col" className="px-3 py-2 font-medium">Category</th>
              <th scope="col" className="px-3 py-2 font-medium">Useful life</th>
              <th scope="col" className="px-3 py-2 font-medium">Asset acct</th>
              <th scope="col" className="px-3 py-2 font-medium">Accum. dep. acct</th>
              <th scope="col" className="px-3 py-2 font-medium">Expense acct</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Assets</th>
              <th scope="col" className="px-3 py-2 font-medium">Status</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Actions</th>
            </tr>
          </thead>
          <tbody>
            {state.items.map((category) => (
              <tr key={category.id} className="border-b border-border/70 align-top">
                <td className="px-3 py-3">
                  <div className="font-medium text-foreground">{category.code}</div>
                  <div className="text-muted-foreground">{category.name}</div>
                </td>
                <td className="px-3 py-3 text-muted-foreground">{usefulLife(category.useful_life_months)}</td>
                <td className="px-3 py-3 text-muted-foreground">
                  {accountLabel(category.asset_account?.code ?? codeById.get(category.asset_account_id), category.asset_account?.name)}
                </td>
                <td className="px-3 py-3 text-muted-foreground">
                  {accountLabel(
                    category.accumulated_depreciation_account?.code ??
                      codeById.get(category.accumulated_depreciation_account_id),
                    category.accumulated_depreciation_account?.name,
                  )}
                </td>
                <td className="px-3 py-3 text-muted-foreground">
                  {accountLabel(
                    category.depreciation_expense_account?.code ??
                      codeById.get(category.depreciation_expense_account_id),
                    category.depreciation_expense_account?.name,
                  )}
                </td>
                <td className="px-3 py-3 text-right tabular-nums text-muted-foreground">
                  {category.assets_count ?? "—"}
                </td>
                <td className="px-3 py-3">
                  {category.is_active ? (
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
                        onClick={() => openEdit(category)}
                      >
                        Edit
                      </button>
                    ) : null}
                    {canUpdate ? (
                      <button
                        type="button"
                        className="text-sm font-medium text-primary hover:underline disabled:text-muted-foreground"
                        disabled={pendingId === category.id}
                        onClick={() => runTransition(category)}
                      >
                        {category.is_active ? "Deactivate" : "Activate"}
                      </button>
                    ) : null}
                    {canDelete ? (
                      <button
                        type="button"
                        className="text-sm font-medium text-danger hover:underline"
                        onClick={() => {
                          setDeleting(category);
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
        <p role="alert" className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground">
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

      <FixedAssetCategoryFormDialog
        open={formOpen}
        companyId={companyId}
        category={editing}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSaved={onSaved}
      />

      <Dialog
        open={Boolean(deleting)}
        title="Delete category"
        onClose={() => setDeleting(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeleting(null)} disabled={pendingId !== null}>
              Cancel
            </Button>
            <Button variant="danger" onClick={confirmDelete} loading={pendingId === deleting?.id}>
              Delete category
            </Button>
          </>
        }
      >
        <p className="text-sm text-muted-foreground">
          Delete <span className="font-medium text-foreground">{deleting?.code}</span> — {deleting?.name}? A
          category that is still referenced by fixed assets cannot be deleted; the server will refuse and the
          reason will be shown.
        </p>
      </Dialog>
    </div>
  );
}
