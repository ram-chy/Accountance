"use client";

import { ErrorState } from "@/components/ui/error-state";
import { LoadingState } from "@/components/ui/loading-state";
import { formatDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import { useFixedAssetSchedule } from "@/lib/fixed-assets/use-fixed-asset-schedule";

/**
 * The asset's remaining depreciation schedule.
 *
 * The rows are the calculator's projection served by
 * `GET .../{id}/depreciation-schedule`, so the amounts shown are the amounts the
 * posting path will charge. Nothing is recomputed in the browser.
 */
export function DepreciationSchedule({
  assetId,
  companyId,
  enabled,
  reloadToken = 0,
}: {
  assetId: number;
  companyId: number | null;
  enabled: boolean;
  reloadToken?: number;
}) {
  const state = useFixedAssetSchedule(assetId, companyId, enabled, reloadToken);

  if (!enabled) {
    return (
      <p className="text-sm text-muted-foreground">
        Your role does not include access to depreciation.
      </p>
    );
  }

  if (state.status === "loading") {
    return <LoadingState label="Loading schedule…" />;
  }

  if (state.status === "unavailable") {
    return <p className="text-sm text-muted-foreground">{state.message ?? "Schedule unavailable."}</p>;
  }

  if (state.status === "error" || state.status === "notfound" || !state.data) {
    return (
      <ErrorState
        title="Could not load schedule"
        message={state.message ?? "The depreciation schedule is unavailable."}
        requestId={state.requestId}
      />
    );
  }

  if (state.data.upcoming_periods.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        No further depreciation is scheduled for this asset.
      </p>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[40rem] border-collapse text-sm">
        <caption className="sr-only">Upcoming depreciation periods</caption>
        <thead>
          <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
            <th scope="col" className="px-3 py-2 font-medium">Period</th>
            <th scope="col" className="px-3 py-2 font-medium">From</th>
            <th scope="col" className="px-3 py-2 font-medium">To</th>
            <th scope="col" className="px-3 py-2 font-medium">Posting date</th>
            <th scope="col" className="px-3 py-2 text-right font-medium">Amount</th>
          </tr>
        </thead>
        <tbody>
          {state.data.upcoming_periods.map((period) => (
            <tr key={period.period_number} className="border-b border-border/70">
              <td className="px-3 py-2 tabular-nums">
                {period.period_number}
                {period.is_final_period ? (
                  <span className="ml-2 text-xs text-muted-foreground">final</span>
                ) : null}
              </td>
              <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(period.period_start_date)}</td>
              <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(period.period_end_date)}</td>
              <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(period.posting_date)}</td>
              <td className="px-3 py-2 text-right tabular-nums">
                {formatMoney(period.amount, { decimals: MONEY_DISPLAY_DECIMALS })}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
