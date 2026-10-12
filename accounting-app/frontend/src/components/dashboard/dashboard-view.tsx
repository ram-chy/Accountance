"use client";

import { useState, type ReactNode } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import {
  MAX_TREND_MONTHS,
  defaultPeriod,
  formatRangeLabel,
  type DashboardPeriod,
} from "@/lib/dashboard/period";
import type { Loadable } from "@/lib/dashboard/types";
import { useDashboardData } from "@/lib/dashboard/use-dashboard-data";
import { formatDateOnly } from "@/lib/format/date";

import { DashboardPageHeader } from "./dashboard-page-header";
import { ExpenseBreakdownChart } from "./expense-breakdown-chart";
import { FinancialChartCard } from "./financial-chart-card";
import { FinancialSummaryGrid } from "./financial-summary-grid";
import { IncomeExpenseChart } from "./income-expense-chart";
import { RecentActivityTable } from "./recent-activity-table";
import { ChartSkeleton } from "./states";

type ChartBodyProps<T> = {
  state: Loadable<T>;
  isEmpty: (data: T) => boolean;
  emptyTitle: string;
  emptyDescription: string;
  children: (data: T) => ReactNode;
};

function ChartBody<T>({
  state,
  isEmpty,
  emptyTitle,
  emptyDescription,
  children,
}: ChartBodyProps<T>) {
  if (state.status === "loading") {
    return <ChartSkeleton />;
  }

  if (state.status === "error") {
    return (
      <ErrorState
        title="Could not load chart"
        message={state.message}
        requestId={state.requestId}
      />
    );
  }

  if (state.status === "unavailable") {
    return <EmptyState title={emptyTitle} description={state.reason} />;
  }

  if (isEmpty(state.data)) {
    return <EmptyState title={emptyTitle} description={emptyDescription} />;
  }

  return <>{children(state.data)}</>;
}

/**
 * Authenticated dashboard.
 *
 * Composes the account overview cards, the income-vs-expenses and expense
 * breakdown charts, and the recent-activity table. All data comes from the
 * Phase 3 proxy via `useDashboardData`; this component only arranges it and
 * gates metrics the current role cannot read. The server wrapper in `page.tsx`
 * owns the page metadata and session guard.
 */
export function DashboardView() {
  const { activeCompany } = useAuth();
  const { permissions, can } = usePermissions();
  const [now] = useState(() => new Date());
  const [periodOverride, setPeriodOverride] = useState<DashboardPeriod | null>(
    null,
  );

  const fiscalStartMonth =
    activeCompany?.settings?.default_fiscal_year_start_month ?? null;
  const period = periodOverride ?? defaultPeriod(now, fiscalStartMonth);

  const data = useDashboardData(period, permissions, activeCompany?.id ?? null);

  const showReports = can("accounting.reports.view");
  const showCashBank =
    can("accounting.cash_bank.view") && can("accounting.ledger.view");

  const periodHint = formatRangeLabel(period.from, period.to);
  const asOfHint = period.to ? `as of ${formatDateOnly(period.to)}` : "as of today";

  return (
    <div className="flex flex-col gap-6">
      <DashboardPageHeader
        company={activeCompany}
        period={period}
        onPeriodChange={setPeriodOverride}
        fiscalStartMonth={fiscalStartMonth}
      />

      {!activeCompany ? (
        <EmptyState
          title="No company selected"
          description="Choose a company to see its accounting overview."
        />
      ) : (
        <>
          {showReports || showCashBank ? (
            <FinancialSummaryGrid
              currency={data.currency}
              periodHint={periodHint}
              asOfHint={asOfHint}
              income={data.income}
              expenses={data.expenses}
              netProfit={data.netProfit}
              receivables={data.receivables}
              payables={data.payables}
              cashBank={data.cashBank}
              showReports={showReports}
              showCashBank={showCashBank}
            />
          ) : (
            <EmptyState
              title="No accounting overview"
              description="Your role does not include access to the account overview metrics."
            />
          )}

          {showReports ? (
            <div className="grid gap-4 xl:grid-cols-2">
              <FinancialChartCard
                title="Income versus expenses"
                description="Monthly revenue and expenses for the selected period."
                footnote={
                  data.trendLimited
                    ? `Showing the most recent ${MAX_TREND_MONTHS} months of this period.`
                    : undefined
                }
              >
                <ChartBody
                  state={data.trend}
                  isEmpty={(points) => points.length === 0}
                  emptyTitle="No trend data"
                  emptyDescription="There is no posted revenue or expenses in this period."
                >
                  {(points) => (
                    <IncomeExpenseChart points={points} currency={data.currency} />
                  )}
                </ChartBody>
              </FinancialChartCard>

              <FinancialChartCard
                title="Expense breakdown"
                description="Expenses grouped by account for the selected period."
              >
                <ChartBody
                  state={data.expenseBreakdown}
                  isEmpty={(rows) => rows.length === 0}
                  emptyTitle="No expenses"
                  emptyDescription="No expense accounts were recorded in this period."
                >
                  {(rows) => (
                    <ExpenseBreakdownChart rows={rows} currency={data.currency} />
                  )}
                </ChartBody>
              </FinancialChartCard>
            </div>
          ) : null}

          <RecentActivityTable state={data.activity} currency={data.currency} />
        </>
      )}
    </div>
  );
}
