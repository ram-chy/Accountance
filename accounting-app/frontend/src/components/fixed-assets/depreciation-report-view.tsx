"use client";

import Link from "next/link";
import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { todayDateOnly, formatDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import { useFixedAssetCategoryOptions } from "@/lib/fixed-asset-categories/use-fixed-asset-category-options";
import { useDepreciationReport } from "@/lib/fixed-assets/use-depreciation-report";

/**
 * Depreciation report.
 *
 * The range is matched against each charge's own period dates (so a run posted in
 * arrears still reports under the month it covers). The total and the per-asset
 * subtotals come from the server; nothing is summed in the browser. A category
 * narrows the report to the assets in that category.
 */

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function monthStart(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  return `${now.getFullYear()}-${month}-01`;
}

function money(value: string | null): string {
  return formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });
}

type Applied = { from: string; to: string; categoryId: string };

export function DepreciationReportView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.fixed_asset.view");

  const [draft, setDraft] = useState<Applied>({ from: monthStart(), to: todayDateOnly(), categoryId: "" });
  const [applied, setApplied] = useState<Applied>(draft);
  const [reloadToken, setReloadToken] = useState(0);

  const categoriesState = useFixedAssetCategoryOptions(companyId, canView);
  const categories = categoriesState.status === "ready" ? categoriesState.items : [];

  const categoryId = Number.parseInt(applied.categoryId, 10);
  const state = useDepreciationReport({
    companyId,
    enabled: canView,
    from: applied.from,
    to: applied.to,
    fixed_asset_category_id: Number.isInteger(categoryId) && categoryId > 0 ? categoryId : undefined,
    reloadToken,
  });

  const header = (
    <div className="flex flex-col gap-1">
      <h1 className="text-2xl font-semibold text-foreground">Depreciation report</h1>
      <p className="text-sm text-muted-foreground">
        Depreciation posted within a date range, grouped by asset. Totals are computed by the server.
      </p>
    </div>
  );

  const filterBar = (
    <form
      className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      onSubmit={(event) => {
        event.preventDefault();
        setApplied(draft);
      }}
    >
      <FormField label="From" htmlFor="fa-report-from" required>
        <Input
          id="fa-report-from"
          type="date"
          value={draft.from}
          onChange={(event) => setDraft((current) => ({ ...current, from: event.target.value }))}
        />
      </FormField>

      <FormField label="To" htmlFor="fa-report-to" required>
        <Input
          id="fa-report-to"
          type="date"
          value={draft.to}
          onChange={(event) => setDraft((current) => ({ ...current, to: event.target.value }))}
        />
      </FormField>

      <FormField label="Category" htmlFor="fa-report-category">
        <select
          id="fa-report-category"
          className={selectClasses}
          value={draft.categoryId}
          onChange={(event) => setDraft((current) => ({ ...current, categoryId: event.target.value }))}
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

      <Button type="submit">Run report</Button>
    </form>
  );

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to run this report." />;
    }
    if (!canView) {
      return (
        <EmptyState
          title="Reports unavailable"
          description="Your role does not include access to fixed assets."
        />
      );
    }
    if (!applied.from || !applied.to) {
      return <EmptyState title="Choose a date range" description="Select a from and to date to run the report." />;
    }
    if (applied.from > applied.to) {
      return <EmptyState title="Invalid date range" description="The from date must not be after the to date." />;
    }
    if (state.status === "loading") {
      return <LoadingState label="Running report…" />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Report unavailable" description={state.message} />;
    }
    if (state.status === "error" || !state.data) {
      return (
        <ErrorState
          title="Could not run the report"
          message={state.message ?? "The depreciation report is unavailable."}
          requestId={state.requestId}
          action={
            <Button variant="secondary" onClick={() => setReloadToken((token) => token + 1)}>
              Try again
            </Button>
          }
        />
      );
    }

    const report = state.data;

    return (
      <div className="flex flex-col gap-6">
        <div className="grid gap-4 sm:grid-cols-3">
          <div className="rounded-lg border border-border bg-surface p-4">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Total depreciation</p>
            <p className="mt-1 text-xl font-semibold tabular-nums text-foreground">
              {money(report.total_depreciation)}
            </p>
          </div>
          <div className="rounded-lg border border-border bg-surface p-4">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Assets</p>
            <p className="mt-1 text-xl font-semibold tabular-nums text-foreground">{report.assets.length}</p>
          </div>
          <div className="rounded-lg border border-border bg-surface p-4">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Period</p>
            <p className="mt-1 text-sm text-foreground">
              {formatDateOnly(report.from)} – {formatDateOnly(report.to)}
            </p>
          </div>
        </div>

        {report.assets.length === 0 ? (
          <EmptyState
            title="No depreciation posted"
            description="No depreciation was posted in the selected period."
          />
        ) : (
          <div className="flex flex-col gap-6">
            {report.assets.map((group) => (
              <section key={group.fixed_asset_id} className="flex flex-col gap-3">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <div>
                    <Link
                      href={`/fixed-assets/${group.fixed_asset_id}`}
                      className="font-medium text-primary hover:underline"
                    >
                      {group.asset_number}
                    </Link>
                    <span className="ml-2 text-sm text-muted-foreground">{group.name}</span>
                    {group.category ? (
                      <span className="ml-2 text-sm text-muted-foreground">· {group.category}</span>
                    ) : null}
                  </div>
                  <div className="text-sm text-muted-foreground">
                    {group.period_count} period{group.period_count === 1 ? "" : "s"} ·{" "}
                    <span className="font-medium tabular-nums text-foreground">
                      {money(group.total_depreciation)}
                    </span>
                  </div>
                </div>

                <div className="overflow-x-auto">
                  <table className="w-full min-w-[44rem] border-collapse text-sm">
                    <caption className="sr-only">Depreciation entries for {group.asset_number}</caption>
                    <thead>
                      <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <th scope="col" className="px-3 py-2 font-medium">Period</th>
                        <th scope="col" className="px-3 py-2 font-medium">From</th>
                        <th scope="col" className="px-3 py-2 font-medium">To</th>
                        <th scope="col" className="px-3 py-2 text-right font-medium">Amount</th>
                        <th scope="col" className="px-3 py-2 font-medium">Journal</th>
                      </tr>
                    </thead>
                    <tbody>
                      {group.entries.map((entry) => (
                        <tr key={entry.id} className="border-b border-border/70">
                          <td className="px-3 py-2 tabular-nums">
                            {entry.period_number}
                            {entry.is_final_period ? (
                              <span className="ml-2 text-xs text-muted-foreground">final</span>
                            ) : null}
                          </td>
                          <td className="px-3 py-2 text-muted-foreground">
                            {formatDateOnly(entry.period_start_date)}
                          </td>
                          <td className="px-3 py-2 text-muted-foreground">
                            {formatDateOnly(entry.period_end_date)}
                          </td>
                          <td className="px-3 py-2 text-right tabular-nums">{money(entry.amount)}</td>
                          <td className="px-3 py-2 text-muted-foreground">
                            {entry.journal ? entry.journal.journal_number : "—"}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            ))}
          </div>
        )}
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      {header}
      {filterBar}
      {body}
    </div>
  );
}
