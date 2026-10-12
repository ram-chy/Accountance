"use client";

import { formatMoneyValue, sumDecimalStrings, toDisplayNumber } from "@/lib/dashboard/format";
import type { AccountAmountRow, CurrencyInfo } from "@/lib/dashboard/types";

export type ExpenseBreakdownChartProps = {
  rows: AccountAmountRow[];
  currency: CurrencyInfo | null;
};

const PERCENT_FORMAT = new Intl.NumberFormat("en-US", {
  maximumFractionDigits: 1,
});

/**
 * Expenses by account, ordered by the backend.
 *
 * Bars are scaled to the largest account for visual comparison; the share of
 * total is shown as a percentage. Both the bar geometry and the percentage use
 * `Number`, which is safe here because they are ratios for presentation only —
 * the amounts themselves are formatted from the original decimal strings.
 */
export function ExpenseBreakdownChart({ rows, currency }: ExpenseBreakdownChartProps) {
  const maximum = rows.reduce(
    (current, row) => Math.max(current, toDisplayNumber(row.amount)),
    0,
  );
  const total = sumDecimalStrings(rows.map((row) => row.amount));
  const totalValue = toDisplayNumber(total);

  return (
    <ul className="flex flex-col gap-4">
      {rows.map((row) => {
        const amount = toDisplayNumber(row.amount);
        const width = maximum > 0 ? (amount / maximum) * 100 : 0;
        const share = totalValue > 0 ? (amount / totalValue) * 100 : 0;

        return (
          <li key={row.accountId} className="flex flex-col gap-1.5">
            <div className="flex items-baseline justify-between gap-3">
              <span className="min-w-0 truncate text-sm text-foreground">
                <span className="mr-2 font-mono text-xs text-muted-foreground">
                  {row.code}
                </span>
                {row.name}
              </span>
              <span className="shrink-0 whitespace-nowrap text-sm font-medium tabular-nums text-foreground">
                {formatMoneyValue(row.amount, currency)}
              </span>
            </div>
            <div
              className="h-2 w-full overflow-hidden rounded-full bg-surface-muted"
              role="img"
              aria-label={`${row.name}: ${formatMoneyValue(
                row.amount,
                currency,
              )}, ${PERCENT_FORMAT.format(share)}% of expenses`}
            >
              <div
                className="h-full rounded-full"
                style={{
                  width: `${Math.max(0, Math.min(100, width))}%`,
                  backgroundColor: "var(--info)",
                }}
              />
            </div>
          </li>
        );
      })}
    </ul>
  );
}
