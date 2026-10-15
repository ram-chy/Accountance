"use client";

import Link from "next/link";
import { useMemo, useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { formatDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import { useFixedAssetCategoryOptions } from "@/lib/fixed-asset-categories/use-fixed-asset-category-options";
import type { FixedAssetListFilters } from "@/lib/fixed-assets/types";
import { FIXED_ASSETS_PER_PAGE, useFixedAssets } from "@/lib/fixed-assets/use-fixed-assets";

/**
 * Disposal register.
 *
 * The backend exposes no disposal-list endpoint, so this view derives the
 * disposal register from the fixed-asset list filtered to `status=DISPOSED`. The
 * gain/loss is a per-asset figure on the disposal and is shown on the asset's
 * detail page; the list shows the book values the list resource carries.
 */

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function money(value: string | null): string {
  return formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });
}

export function DisposalsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.fixed_asset.view");

  const [search, setSearch] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [page, setPage] = useState(1);
  const [reloadToken, setReloadToken] = useState(0);

  const categoriesState = useFixedAssetCategoryOptions(companyId, canView);
  const categories = categoriesState.status === "ready" ? categoriesState.items : [];

  const filters = useMemo<FixedAssetListFilters>(() => {
    const parsedCategory = Number.parseInt(categoryId, 10);
    return {
      status: "DISPOSED",
      search: appliedSearch.trim() || undefined,
      fixed_asset_category_id:
        Number.isInteger(parsedCategory) && parsedCategory > 0 ? parsedCategory : undefined,
    };
  }, [appliedSearch, categoryId]);

  const state = useFixedAssets({
    companyId,
    enabled: canView,
    filters,
    page,
    perPage: FIXED_ASSETS_PER_PAGE,
    reloadToken,
    mode: "index",
  });

  const isFiltered = appliedSearch.trim() !== "" || categoryId !== "";

  const header = (
    <div className="flex flex-col gap-1">
      <h1 className="text-2xl font-semibold text-foreground">Disposals</h1>
      <p className="text-sm text-muted-foreground">
        Fixed assets that have been disposed of. Open an asset for its disposal and gain/loss.
      </p>
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
      <FormField label="Search" htmlFor="fa-disposals-search">
        <Input
          id="fa-disposals-search"
          value={search}
          placeholder="Asset code or name"
          onChange={(event) => setSearch(event.target.value)}
        />
      </FormField>

      <FormField label="Category" htmlFor="fa-disposals-category">
        <select
          id="fa-disposals-category"
          className={selectClasses}
          value={categoryId}
          onChange={(event) => {
            setCategoryId(event.target.value);
            setPage(1);
          }}
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

      <div className="flex gap-2">
        <Button type="submit">Apply</Button>
        {isFiltered ? (
          <Button
            type="button"
            variant="ghost"
            onClick={() => {
              setSearch("");
              setAppliedSearch("");
              setCategoryId("");
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
      return <EmptyState title="No company selected" description="Choose a company to view disposals." />;
    }
    if (!canView) {
      return (
        <EmptyState
          title="Disposals unavailable"
          description="Your role does not include access to fixed assets."
        />
      );
    }
    if (state.status === "loading") {
      return <p className="text-sm text-muted-foreground">Loading disposals…</p>;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Disposals unavailable" description={state.message} />;
    }
    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load disposals"
          message={state.message}
          requestId={state.requestId}
          action={
            <Button variant="secondary" onClick={() => setReloadToken((token) => token + 1)}>
              Try again
            </Button>
          }
        />
      );
    }
    if (state.items.length === 0) {
      return (
        <EmptyState
          title={isFiltered ? "No matching disposals" : "No disposals yet"}
          description={
            isFiltered
              ? "No disposed assets match the current filters."
              : "No fixed assets have been disposed of."
          }
        />
      );
    }

    return (
      <div className="overflow-x-auto">
        <table className="w-full min-w-[52rem] border-collapse text-sm">
          <caption className="sr-only">Disposed fixed assets</caption>
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
              <th scope="col" className="px-3 py-2 font-medium">Asset</th>
              <th scope="col" className="px-3 py-2 font-medium">Category</th>
              <th scope="col" className="px-3 py-2 font-medium">Acquired</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Cost</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Accum. dep.</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Net book value</th>
              <th scope="col" className="px-3 py-2 text-right font-medium">Actions</th>
            </tr>
          </thead>
          <tbody>
            {state.items.map((asset) => (
              <tr key={asset.id} className="border-b border-border/70">
                <td className="px-3 py-3">
                  <Link href={`/fixed-assets/${asset.id}`} className="font-medium text-primary hover:underline">
                    {asset.asset_number}
                  </Link>
                  <div className="text-muted-foreground">{asset.name}</div>
                </td>
                <td className="px-3 py-3 text-muted-foreground">{asset.category ? asset.category.name : "—"}</td>
                <td className="px-3 py-3 text-muted-foreground">{formatDateOnly(asset.acquisition_date)}</td>
                <td className="px-3 py-3 text-right tabular-nums">{money(asset.original_cost)}</td>
                <td className="px-3 py-3 text-right tabular-nums">{money(asset.accumulated_depreciation)}</td>
                <td className="px-3 py-3 text-right tabular-nums">{money(asset.carrying_value)}</td>
                <td className="px-3 py-3 text-right">
                  <Link
                    href={`/fixed-assets/${asset.id}`}
                    className="text-sm font-medium text-primary hover:underline"
                  >
                    View
                  </Link>
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
    </div>
  );
}
