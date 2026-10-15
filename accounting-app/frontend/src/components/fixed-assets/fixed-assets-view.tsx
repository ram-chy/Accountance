"use client";

import Link from "next/link";
import { useMemo, useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { useFixedAssetCategoryOptions } from "@/lib/fixed-asset-categories/use-fixed-asset-category-options";
import {
  FIXED_ASSET_ACQUISITION_METHODS,
  FIXED_ASSET_STATUSES,
  type FixedAssetAcquisitionMethod,
  type FixedAssetListFilters,
  type FixedAssetStatus,
} from "@/lib/fixed-assets/types";
import { FIXED_ASSETS_PER_PAGE, useFixedAssets } from "@/lib/fixed-assets/use-fixed-assets";

import { FixedAssetsListSkeleton } from "./fixed-assets-list-skeleton";
import { FixedAssetsTable } from "./fixed-assets-table";

/**
 * Fixed-asset listing screen.
 *
 * Two verified endpoints back the same UI: `mode="index"` reads
 * `GET accounting/fixed-assets` (every lifecycle state, so drafts stay visible
 * and can be capitalised) and `mode="register"` reads
 * `GET accounting/fixed-assets/register` (the capitalised assets only). Both
 * return flat arrays, so filters, the responsive list, and `hasMore` pagination
 * are shared. The status filter is hidden in register mode because the register
 * is defined by status and offering a contradictory filter would be misleading.
 * Writes happen on the detail/new screens.
 */

const STATUS_LABEL: Record<FixedAssetStatus, string> = {
  DRAFT: "Draft",
  ACTIVE: "Active",
  FULLY_DEPRECIATED: "Fully depreciated",
  DISPOSED: "Disposed",
};

const METHOD_LABEL: Record<FixedAssetAcquisitionMethod, string> = {
  CASH: "Cash",
  SUPPLIER_CREDIT: "Supplier credit",
};

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type Query = {
  status: FixedAssetStatus | "";
  acquisition_method: FixedAssetAcquisitionMethod | "";
  fixed_asset_category_id: string;
  from: string;
  to: string;
  search: string;
  page: number;
};

const INITIAL_QUERY: Query = {
  status: "",
  acquisition_method: "",
  fixed_asset_category_id: "",
  from: "",
  to: "",
  search: "",
  page: 1,
};

function toFilters(query: Query): FixedAssetListFilters {
  const categoryId = Number.parseInt(query.fixed_asset_category_id, 10);
  return {
    status: query.status === "" ? undefined : query.status,
    acquisition_method: query.acquisition_method === "" ? undefined : query.acquisition_method,
    fixed_asset_category_id: Number.isInteger(categoryId) && categoryId > 0 ? categoryId : undefined,
    from: query.from || undefined,
    to: query.to || undefined,
    search: query.search.trim() || undefined,
  };
}

export function FixedAssetsView({ mode = "index" }: { mode?: "index" | "register" } = {}) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const isRegister = mode === "register";

  const canView = can("accounting.fixed_asset.view");
  const canCreate = can("accounting.fixed_asset.create");
  const canUpdate = can("accounting.fixed_asset.update");

  const [query, setQuery] = useState<Query>(INITIAL_QUERY);
  const [reloadToken, setReloadToken] = useState(0);
  const [showFilters, setShowFilters] = useState(false);

  const filters = useMemo(() => toFilters(query), [query]);

  const categoriesState = useFixedAssetCategoryOptions(companyId, canView);
  const categories = categoriesState.status === "ready" ? categoriesState.items : [];

  const state = useFixedAssets({
    companyId,
    enabled: canView,
    filters,
    page: query.page,
    perPage: FIXED_ASSETS_PER_PAGE,
    reloadToken,
    mode,
  });

  const reload = () => setReloadToken((token) => token + 1);
  const isFiltered =
    query.status !== "" ||
    query.acquisition_method !== "" ||
    query.fixed_asset_category_id !== "" ||
    query.from !== "" ||
    query.to !== "" ||
    query.search !== "";

  const onClearFilters = () => setQuery(INITIAL_QUERY);

  const header = (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold text-foreground">
          {isRegister ? "Fixed asset register" : "Fixed assets"}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {isRegister
            ? "Capitalised assets, with cost, accumulated depreciation, and net book value as computed by the server."
            : "The fixed-asset register and lifecycle for the active company."}
        </p>
      </div>
      {canCreate ? (
        <Link href="/fixed-assets/new">
          <Button type="button">New fixed asset</Button>
        </Link>
      ) : null}
    </div>
  );

  const filterBar = (
    <form
      className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4"
      onSubmit={(event) => {
        event.preventDefault();
        setQuery((current) => ({ ...current, page: 1 }));
        setShowFilters(false);
      }}
    >
      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
        <FormField label="Search" htmlFor="fa-search">
          <Input
            id="fa-search"
            value={query.search}
            placeholder="Asset code or name"
            onChange={(event) => setQuery((current) => ({ ...current, search: event.target.value, page: 1 }))}
            disabled={!activeCompany}
          />
        </FormField>
        <div className="flex gap-2 pb-2">
          <Button type="submit" disabled={!activeCompany}>
            Apply
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => setShowFilters((value) => !value)}
            disabled={!activeCompany}
          >
            {showFilters ? "Hide filters" : "More filters"}
          </Button>
          {isFiltered ? (
            <Button type="button" variant="ghost" onClick={onClearFilters}>
              Clear
            </Button>
          ) : null}
        </div>
      </div>

      {showFilters ? (
        <div className="grid gap-3 border-t border-border pt-3 sm:grid-cols-2 lg:grid-cols-3">
          {!isRegister ? (
            <FormField label="Status" htmlFor="fa-status">
              <select
                id="fa-status"
                className={selectClasses}
                value={query.status}
                onChange={(event) =>
                  setQuery((current) => ({
                    ...current,
                    status: event.target.value as FixedAssetStatus | "",
                    page: 1,
                  }))
                }
              >
                <option value="">All statuses</option>
                {FIXED_ASSET_STATUSES.map((value) => (
                  <option key={value} value={value}>
                    {STATUS_LABEL[value]}
                  </option>
                ))}
              </select>
            </FormField>
          ) : null}

          <FormField label="Acquisition method" htmlFor="fa-method">
            <select
              id="fa-method"
              className={selectClasses}
              value={query.acquisition_method}
              onChange={(event) =>
                setQuery((current) => ({
                  ...current,
                  acquisition_method: event.target.value as FixedAssetAcquisitionMethod | "",
                  page: 1,
                }))
              }
            >
              <option value="">All methods</option>
              {FIXED_ASSET_ACQUISITION_METHODS.map((value) => (
                <option key={value} value={value}>
                  {METHOD_LABEL[value]}
                </option>
              ))}
            </select>
          </FormField>

          <FormField label="Category" htmlFor="fa-category">
            <select
              id="fa-category"
              className={selectClasses}
              value={query.fixed_asset_category_id}
              onChange={(event) =>
                setQuery((current) => ({ ...current, fixed_asset_category_id: event.target.value, page: 1 }))
              }
              disabled={categoriesState.status !== "ready"}
            >
              <option value="">All categories</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.code} — {category.name}
                </option>
              ))}
            </select>
          </FormField>

          <FormField label="Acquired from" htmlFor="fa-from">
            <Input
              id="fa-from"
              type="date"
              value={query.from}
              onChange={(event) => setQuery((current) => ({ ...current, from: event.target.value, page: 1 }))}
            />
          </FormField>

          <FormField label="Acquired to" htmlFor="fa-to">
            <Input
              id="fa-to"
              type="date"
              value={query.to}
              onChange={(event) => setQuery((current) => ({ ...current, to: event.target.value, page: 1 }))}
            />
          </FormField>
        </div>
      ) : null}
    </form>
  );

  const body = (() => {
    if (!activeCompany) {
      return (
        <EmptyState
          title="No company selected"
          description="Choose a company to view its fixed assets."
        />
      );
    }

    if (!canView) {
      return (
        <EmptyState
          title="Fixed assets unavailable"
          description="Your role does not include access to fixed assets."
        />
      );
    }

    if (state.status === "loading") {
      return <FixedAssetsListSkeleton />;
    }

    if (state.status === "unavailable") {
      return <EmptyState title="Fixed assets unavailable" description={state.message} />;
    }

    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load fixed assets"
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
            title="No fixed assets on this page"
            description="This page is empty. Return to the previous page to see the remaining assets."
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
            title="No fixed assets match"
            description="No fixed assets match the current filters."
            action={
              <Button variant="secondary" onClick={onClearFilters}>
                Clear filters
              </Button>
            }
          />
        );
      }

      if (isRegister) {
        return (
          <EmptyState
            title="Register is empty"
            description="No capitalised fixed assets exist yet. Capitalise a draft asset to list it here."
          />
        );
      }

      return (
        <EmptyState
          title="No fixed assets yet"
          description="Create the first fixed asset to start tracking this company's depreciation."
          action={
            canCreate ? (
              <Link href="/fixed-assets/new">
                <Button type="button">New fixed asset</Button>
              </Link>
            ) : undefined
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <FixedAssetsTable items={state.items} canUpdate={canUpdate} />
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
            Showing {state.items.length} fixed asset{state.items.length === 1 ? "" : "s"} on page{" "}
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
