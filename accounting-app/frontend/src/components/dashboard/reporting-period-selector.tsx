"use client";

import { useId } from "react";

import {
  PERIOD_PRESETS,
  formatRangeLabel,
  resolvePeriod,
  type DashboardPeriod,
  type PeriodPreset,
} from "@/lib/dashboard/period";

export type ReportingPeriodSelectorProps = {
  period: DashboardPeriod;
  onChange: (period: DashboardPeriod) => void;
  /** Company fiscal-year start month (1–12); drives financial-year presets. */
  fiscalStartMonth: number | null;
  className?: string;
};

/**
 * Optional reporting-period control.
 *
 * Presets resolve to concrete ranges using the configured fiscal-year start;
 * editing either date switches the control into a custom range. Both inputs are
 * native date fields, so values are already in the backend's `YYYY-MM-DD` shape.
 */
export function ReportingPeriodSelector({
  period,
  onChange,
  fiscalStartMonth,
  className,
}: ReportingPeriodSelectorProps) {
  const presetId = useId();
  const fromId = useId();
  const toId = useId();

  function handlePresetChange(value: string) {
    const preset = value as PeriodPreset;
    if (preset === "custom") {
      onChange({ ...period, preset });
      return;
    }
    const range = resolvePeriod(preset, new Date(), fiscalStartMonth);
    onChange({ preset, from: range.from, to: range.to });
  }

  return (
    <div className={className}>
      <div className="flex flex-wrap items-end gap-3">
        <div className="flex flex-col gap-1">
          <label
            htmlFor={presetId}
            className="text-xs font-medium text-muted-foreground"
          >
            Reporting period
          </label>
          <select
            id={presetId}
            value={period.preset}
            onChange={(event) => handlePresetChange(event.target.value)}
            className="h-9 rounded-lg border border-border bg-surface px-3 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            {PERIOD_PRESETS.map((preset) => (
              <option key={preset.value} value={preset.value}>
                {preset.label}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-1">
          <label
            htmlFor={fromId}
            className="text-xs font-medium text-muted-foreground"
          >
            From
          </label>
          <input
            id={fromId}
            type="date"
            value={period.from ?? ""}
            max={period.to ?? undefined}
            onChange={(event) =>
              onChange({
                preset: "custom",
                from: event.target.value,
                to: period.to,
              })
            }
            className="h-9 rounded-lg border border-border bg-surface px-3 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>

        <div className="flex flex-col gap-1">
          <label
            htmlFor={toId}
            className="text-xs font-medium text-muted-foreground"
          >
            To
          </label>
          <input
            id={toId}
            type="date"
            value={period.to ?? ""}
            min={period.from ?? undefined}
            onChange={(event) =>
              onChange({
                preset: "custom",
                from: period.from,
                to: event.target.value,
              })
            }
            className="h-9 rounded-lg border border-border bg-surface px-3 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </div>
      </div>

      <p className="mt-2 text-xs text-muted-foreground">
        {formatRangeLabel(period.from, period.to)}
      </p>
    </div>
  );
}
