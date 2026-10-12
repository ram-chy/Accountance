"use client";

import type { SessionCompany } from "@/lib/auth/types";
import type { DashboardPeriod } from "@/lib/dashboard/period";

import { ReportingPeriodSelector } from "./reporting-period-selector";

export type DashboardPageHeaderProps = {
  company: SessionCompany | null;
  period: DashboardPeriod;
  onPeriodChange: (period: DashboardPeriod) => void;
  fiscalStartMonth: number | null;
};

/**
 * Page title, active-company context and the reporting-period control.
 */
export function DashboardPageHeader({
  company,
  period,
  onPeriodChange,
  fiscalStartMonth,
}: DashboardPageHeaderProps) {
  return (
    <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">Dashboard</h1>
        <p className="text-sm text-muted-foreground">
          {company
            ? `Financial overview for ${company.name}.`
            : "Financial overview."}
        </p>
      </div>

      {company ? (
        <ReportingPeriodSelector
          period={period}
          onChange={onPeriodChange}
          fiscalStartMonth={fiscalStartMonth}
          className="lg:max-w-md"
        />
      ) : null}
    </div>
  );
}
