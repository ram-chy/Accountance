"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useState, type FormEvent, type ReactNode } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { BudgetStatusBadge } from "@/components/budgets/budget-status-badge";
import { Badge, type BadgeVariant } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import {
  BUDGET_VARIANCE_STATUS_LABELS,
  type BudgetVarianceLine,
  type BudgetVarianceReport,
  type BudgetVarianceStatusValue,
  type BudgetVarianceSummarySection,
} from "@/lib/budgets/types";
import { useBudgetOptions } from "@/lib/budgets/use-budget-options";
import { useBudgetVariance } from "@/lib/budgets/use-budget-variance";
import { useFinancialDimensionOptions } from "@/lib/dimensions/use-dimension-options";
import { useFinancialDimensionValueOptions } from "@/lib/dimensions/use-dimension-value-options";
import { formatDateOnly } from "@/lib/format/date";
import { formatDecimal, formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";

/**
 * Budget variance report.
 *
 * Compares one budget's plan with the posted ledger, per account and accounting
 * period. Every figure — the plan, the actual, the variance, the percentage — is
 * produced by the backend and rendered as the canonical decimal string it sent;
 * the browser derives no total and performs no percentage arithmetic, because
 * the `variance_percentage` is `null` exactly when the plan was zero and only the
 * server knows what "favourable" means for a given account type.
 *
 * Variance is defined per budget (there is no standalone endpoint), so the report
 * waits for a budget to be chosen. The budget and dimension filters seed from the
 * URL so a link from a budget (`/reports/budget-variance?budget_id=…`) opens on
 * that plan. The dimension filter is the same one the backend applies to both
 * sides of the comparison.
 */

const permissionMsg = "Your role does not include access to budgets.";

const selectClasses =
  "h-10 min-w-56 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

const VARIANCE_STATUS_VARIANTS: Record<BudgetVarianceStatusValue, BadgeVariant> = {
  FAVOURABLE: "success",
  UNFAVOURABLE: "danger",
  ON_TARGET: "neutral",
};

const VARIANCE_STATUS_TEXT: Record<BudgetVarianceStatusValue, string> = {
  FAVOURABLE: "text-success",
  UNFAVOURABLE: "text-danger",
  ON_TARGET: "text-foreground",
};

function VarianceStatusBadge({ status }: { status: BudgetVarianceStatusValue }) {
  return <Badge variant={VARIANCE_STATUS_VARIANTS[status]}>{BUDGET_VARIANCE_STATUS_LABELS[status]}</Badge>;
}

type SearchParamsLike = { get(key: string): string | null };

/** The report's own filters; a `null` id means "not chosen" / "all dimensions". */
export type BudgetVarianceViewFilters = {
  budget_id: number | null;
  dimension_id: number | null;
  dimension_value_id: number | null;
};

const EMPTY_FILTERS: BudgetVarianceViewFilters = {
  budget_id: null,
  dimension_id: null,
  dimension_value_id: null,
};

function parseId(value: string | null): number | null {
  if (value === null || value === "") return null;
  const parsed = Number.parseInt(value, 10);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

/**
 * Seed the report filters from the URL so a budget's "View variance" link opens
 * on that plan. Values are read once, as the initial state; the URL is not
 * re-read on every render and no effect writes state back into the form.
 */
export function readBudgetVarianceFilters(params: SearchParamsLike): BudgetVarianceViewFilters {
  return {
    budget_id: parseId(params.get("budget_id")),
    dimension_id: parseId(params.get("dimension_id")),
    dimension_value_id: parseId(params.get("dimension_value_id")),
  };
}

function SummaryCard({
  title,
  section,
  decimals,
  status,
  hint,
}: {
  title: string;
  section: BudgetVarianceSummarySection;
  decimals: number;
  status?: BudgetVarianceStatusValue;
  hint?: ReactNode;
}) {
  const money = (value: string) => formatMoney(value, { decimals });
  return (
    <Card>
      <CardHeader>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <CardTitle>{title}</CardTitle>
          {status ? <VarianceStatusBadge status={status} /> : null}
        </div>
      </CardHeader>
      <CardContent>
        <dl className="flex flex-col gap-2">
          <div className="flex items-center justify-between gap-4">
            <dt className="text-sm text-muted-foreground">Budget</dt>
            <dd className="tabular-nums text-sm text-foreground">{money(section.budget)}</dd>
          </div>
          <div className="flex items-center justify-between gap-4">
            <dt className="text-sm text-muted-foreground">Actual</dt>
            <dd className="tabular-nums text-sm text-foreground">{money(section.actual)}</dd>
          </div>
          <div className="flex items-center justify-between gap-4 border-t border-border pt-2">
            <dt className="text-sm font-medium text-foreground">Variance</dt>
            <dd
              className={`tabular-nums text-sm font-medium ${
                status ? VARIANCE_STATUS_TEXT[status] : "text-foreground"
              }`}
            >
              {money(section.variance)}
            </dd>
          </div>
        </dl>
        {hint ? <div className="mt-3 text-xs text-muted-foreground">{hint}</div> : null}
      </CardContent>
    </Card>
  );
}

function VarianceTable({
  report,
  decimals,
  filtered,
}: {
  report: BudgetVarianceReport;
  decimals: number;
  filtered: boolean;
}) {
  if (report.lines.length === 0) {
    return (
      <EmptyState
        title={filtered ? "No lines match this filter" : "No budget lines"}
        description={
          filtered
            ? "No planned line carries the selected dimension value."
            : "This budget has no lines to compare against the ledger yet."
        }
      />
    );
  }

  const money = (value: string) => formatMoney(value, { decimals });
  const percent = (value: string | null) =>
    value === null ? "n/a" : `${formatDecimal(value, { decimals: 2, fallback: "—" })}%`;

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[52rem] border-collapse text-sm">
        <caption className="sr-only">Budget versus actual</caption>
        <thead>
          <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
            <th scope="col" className="px-3 py-2 font-medium">Account</th>
            <th scope="col" className="px-3 py-2 font-medium">Period</th>
            <th scope="col" className="px-3 py-2 text-right font-medium">Budget</th>
            <th scope="col" className="px-3 py-2 text-right font-medium">Actual</th>
            <th scope="col" className="px-3 py-2 text-right font-medium">Variance</th>
            <th scope="col" className="px-3 py-2 text-right font-medium">Variance %</th>
            <th scope="col" className="px-3 py-2 font-medium">Status</th>
          </tr>
        </thead>
        <tbody>
          {report.lines.map((line: BudgetVarianceLine) => (
            <tr
              key={`${line.account_id}:${line.accounting_period_id}`}
              className="border-b border-border/70 align-top"
            >
              <td className="px-3 py-3">
                <div className="font-medium text-foreground">{line.account_code}</div>
                <div className="text-muted-foreground">{line.account_name}</div>
              </td>
              <td className="px-3 py-3 text-muted-foreground">{line.period_name}</td>
              <td className="px-3 py-3 text-right tabular-nums">{money(line.budget)}</td>
              <td className="px-3 py-3 text-right tabular-nums">{money(line.actual)}</td>
              <td
                className={`px-3 py-3 text-right tabular-nums font-medium ${VARIANCE_STATUS_TEXT[line.status]}`}
              >
                {money(line.variance)}
              </td>
              <td className="px-3 py-3 text-right tabular-nums text-muted-foreground">
                {percent(line.variance_percentage)}
              </td>
              <td className="px-3 py-3">
                <VarianceStatusBadge status={line.status} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function BudgetVarianceView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const canView = can("accounting.budgets.view");
  const searchParams = useSearchParams();

  const [draft, setDraft] = useState<BudgetVarianceViewFilters>(() => readBudgetVarianceFilters(searchParams));
  const [applied, setApplied] = useState<BudgetVarianceViewFilters>(() => readBudgetVarianceFilters(searchParams));
  const [reloadToken, setReloadToken] = useState(0);

  const budgetsState = useBudgetOptions(companyId, canView && companyId !== null);
  const budgets = budgetsState.status === "ready" ? budgetsState.items : [];

  const dimensionsState = useFinancialDimensionOptions(companyId, canView && companyId !== null);
  const dimensions = dimensionsState.status === "ready" ? dimensionsState.items.filter((d) => d.is_active) : [];

  const valueState = useFinancialDimensionValueOptions(
    draft.dimension_id ?? 0,
    canView && draft.dimension_id !== null,
  );
  const values = valueState.status === "ready" ? valueState.items.filter((v) => v.is_active) : [];

  const state = useBudgetVariance({
    budgetId: applied.budget_id,
    companyId,
    enabled: canView,
    filters: { dimension_id: applied.dimension_id, dimension_value_id: applied.dimension_value_id },
    reloadToken,
  });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
  };

  const clear = () => {
    setDraft(EMPTY_FILTERS);
    setApplied(EMPTY_FILTERS);
  };

  const filterBar = (
    <form
      onSubmit={apply}
      className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
    >
      <FormField label="Budget" htmlFor="bv-budget">
        <select
          id="bv-budget"
          className={selectClasses}
          value={draft.budget_id === null ? "" : String(draft.budget_id)}
          onChange={(event) =>
            setDraft((current) => ({ ...current, budget_id: parseId(event.target.value) }))
          }
          disabled={budgetsState.status !== "ready"}
        >
          <option value="">Select a budget…</option>
          {budgets.map((budget) => (
            <option key={budget.id} value={budget.id}>
              {budget.code} — {budget.name} (v{budget.version_number})
            </option>
          ))}
        </select>
      </FormField>

      <FormField label="Dimension" htmlFor="bv-dimension">
        <select
          id="bv-dimension"
          className={selectClasses}
          value={draft.dimension_id === null ? "" : String(draft.dimension_id)}
          onChange={(event) =>
            setDraft((current) => ({
              ...current,
              dimension_id: parseId(event.target.value),
              dimension_value_id: null,
            }))
          }
          disabled={dimensionsState.status !== "ready"}
        >
          <option value="">All dimensions</option>
          {dimensions.map((dimension) => (
            <option key={dimension.id} value={dimension.id}>
              {dimension.name} ({dimension.type_label})
            </option>
          ))}
        </select>
      </FormField>

      <FormField label="Dimension value" htmlFor="bv-dimension-value">
        <select
          id="bv-dimension-value"
          className={selectClasses}
          value={draft.dimension_value_id === null ? "" : String(draft.dimension_value_id)}
          onChange={(event) =>
            setDraft((current) => ({ ...current, dimension_value_id: parseId(event.target.value) }))
          }
          disabled={draft.dimension_id === null || valueState.status !== "ready"}
        >
          <option value="">All values</option>
          {values.map((value) => (
            <option key={value.id} value={value.id}>
              {value.code} — {value.name}
            </option>
          ))}
        </select>
      </FormField>

      <div className="flex gap-2 pb-2">
        <Button type="submit">Apply</Button>
        <Button type="button" variant="secondary" onClick={clear}>
          Clear
        </Button>
      </div>
    </form>
  );

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description="Choose a company to view its budget variance." />;
    }
    if (!canView) {
      return <EmptyState title="Budgets unavailable" description={permissionMsg} />;
    }
    if (budgetsState.status === "error") {
      return (
        <ErrorState
          title="Budgets unavailable"
          message={budgetsState.message ?? "We could not load the budget list."}
        />
      );
    }
    if (budgetsState.status === "loading") {
      return <LoadingState label="Loading budgets…" />;
    }
    if (applied.budget_id === null) {
      return (
        <EmptyState
          title="Select a budget"
          description="Choose a budget to compare its plan with the posted ledger."
        />
      );
    }
    if (state.status === "notfound") {
      return (
        <EmptyState
          title="Budget not found"
          description="This budget does not exist or is not in this company."
        />
      );
    }
    if (state.status === "loading") {
      return <LoadingState label="Loading budget variance…" />;
    }
    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load budget variance"
          message={state.message ?? "The report is unavailable."}
          requestId={state.requestId}
          action={
            <Button type="button" onClick={() => setReloadToken((token) => token + 1)}>
              Retry
            </Button>
          }
        />
      );
    }
    if (state.status === "unavailable" || !state.data) {
      return <EmptyState title="Budgets unavailable" description={state.message ?? permissionMsg} />;
    }

    const report = state.data;
    const decimals = report.base_currency.decimals ?? MONEY_DISPLAY_DECIMALS;
    const filter = report.dimension_filter;
    const filterDimension =
      filter?.dimension_id != null
        ? dimensions.find((dimension) => dimension.id === filter.dimension_id)
        : undefined;
    const filterValue =
      filter?.dimension_value_id != null
        ? values.find((value) => value.id === filter.dimension_value_id)
        : undefined;

    return (
      <div className="flex flex-col gap-6">
        <div className="flex flex-col gap-2">
          <Link href={`/budgets/${report.budget.id}`} className="text-sm font-medium text-primary hover:underline">
            ← Budget
          </Link>
          <div className="flex flex-wrap items-center gap-3">
            <h2 className="text-xl font-semibold text-foreground">{report.budget.code}</h2>
            <Badge variant="neutral">Version {report.budget.version_number}</Badge>
            <BudgetStatusBadge status={report.budget.status} />
          </div>
          <p className="text-sm text-muted-foreground">{report.budget.name}</p>
          <p className="text-sm text-muted-foreground">
            {report.budget.financial_year.name ?? "—"} · {formatDateOnly(report.period.from)} →{" "}
            {formatDateOnly(report.period.to)}
          </p>
          {filter ? (
            <p className="text-sm text-muted-foreground">
              Dimension filter:{" "}
              {filterDimension ? filterDimension.name : `#${filter.dimension_id ?? "—"}`}
              {filter.dimension_value_id != null
                ? ` — ${filterValue ? `${filterValue.code} ${filterValue.name}` : `#${filter.dimension_value_id}`}`
                : " (all values)"}
            </p>
          ) : null}
        </div>

        <div className="grid gap-4 lg:grid-cols-3">
          <SummaryCard title="Revenue" section={report.summary.revenue} decimals={decimals} />
          <SummaryCard title="Expenses" section={report.summary.expenses} decimals={decimals} />
          <SummaryCard
            title="Net"
            section={report.summary.net}
            decimals={decimals}
            status={report.summary.net.status}
          />
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Line results</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="flex flex-wrap gap-6">
              <div className="flex flex-col gap-0.5">
                <dt className="text-xs uppercase tracking-wide text-muted-foreground">Favourable</dt>
                <dd className="tabular-nums text-sm font-medium text-success">
                  {report.summary.counts.favourable}
                </dd>
              </div>
              <div className="flex flex-col gap-0.5">
                <dt className="text-xs uppercase tracking-wide text-muted-foreground">Unfavourable</dt>
                <dd className="tabular-nums text-sm font-medium text-danger">
                  {report.summary.counts.unfavourable}
                </dd>
              </div>
              <div className="flex flex-col gap-0.5">
                <dt className="text-xs uppercase tracking-wide text-muted-foreground">On target</dt>
                <dd className="tabular-nums text-sm font-medium text-foreground">
                  {report.summary.counts.on_target}
                </dd>
              </div>
            </dl>
          </CardContent>
        </Card>

        <VarianceTable report={report} decimals={decimals} filtered={Boolean(filter)} />
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Budget Variance</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          A budget&apos;s plan compared with the posted actuals, per account and period.
        </p>
      </div>
      {filterBar}
      {body}
    </div>
  );
}
