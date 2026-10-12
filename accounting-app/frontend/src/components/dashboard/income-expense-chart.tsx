"use client";

import {
  formatCompactNumber,
  formatMoneyValue,
  toDisplayNumber,
} from "@/lib/dashboard/format";
import type { CurrencyInfo, TrendPoint } from "@/lib/dashboard/types";

export type IncomeExpenseChartProps = {
  points: TrendPoint[];
  currency: CurrencyInfo | null;
};

const WIDTH = 720;
const HEIGHT = 280;
const PADDING = { top: 16, right: 16, bottom: 44, left: 64 };
const PLOT_WIDTH = WIDTH - PADDING.left - PADDING.right;
const PLOT_HEIGHT = HEIGHT - PADDING.top - PADDING.bottom;
const BAR_GAP = 4;
const MAX_BAR_WIDTH = 28;

/** Round an axis maximum up to a 1/2/5 × 10ⁿ boundary. */
function niceCeil(value: number): number {
  if (!Number.isFinite(value) || value <= 0) {
    return 1;
  }

  const exponent = Math.floor(Math.log10(value));
  const base = 10 ** exponent;
  const normalised = value / base;
  const stepped =
    normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 5 ? 5 : 10;

  return stepped * base;
}

function shortLabel(label: string): string {
  return label.split(" ")[0] ?? label;
}

/**
 * Income versus expenses per month.
 *
 * A hand-rolled SVG bar chart (no charting dependency). Values are scaled with
 * `Number` for geometry only — the figures shown in tooltips and the accessible
 * description use the precision-safe money formatter, and no total is ever
 * computed here.
 */
export function IncomeExpenseChart({ points, currency }: IncomeExpenseChartProps) {
  const maximum = niceCeil(
    points.reduce(
      (current, point) =>
        Math.max(
          current,
          toDisplayNumber(point.revenue),
          toDisplayNumber(point.expenses),
        ),
      0,
    ),
  );

  const groupWidth = PLOT_WIDTH / points.length;
  const barWidth = Math.min(MAX_BAR_WIDTH, groupWidth * 0.3);
  const baseline = PADDING.top + PLOT_HEIGHT;
  const y = (value: number) => baseline - (value / maximum) * PLOT_HEIGHT;
  const gridValues = [0, maximum / 2, maximum];
  const ariaLabel = `Income versus expenses from ${points[0].label} to ${
    points[points.length - 1].label
  }.`;

  return (
    <figure className="w-full">
      <div className="mb-3 flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
        <span className="inline-flex items-center gap-1.5">
          <span
            aria-hidden="true"
            className="h-2.5 w-2.5 rounded-sm"
            style={{ backgroundColor: "var(--primary)" }}
          />
          Revenue
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span
            aria-hidden="true"
            className="h-2.5 w-2.5 rounded-sm"
            style={{ backgroundColor: "var(--info)" }}
          />
          Expenses
        </span>
      </div>

      <svg
        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
        className="h-auto w-full"
        role="img"
        aria-label={ariaLabel}
      >
        <title>{ariaLabel}</title>

        {gridValues.map((value) => (
          <g key={value}>
            <line
              x1={PADDING.left}
              x2={WIDTH - PADDING.right}
              y1={y(value)}
              y2={y(value)}
              style={{ stroke: "var(--border)" }}
              strokeWidth={1}
            />
            <text
              x={PADDING.left - 8}
              y={y(value)}
              textAnchor="end"
              dominantBaseline="middle"
              fontSize={11}
              style={{ fill: "var(--muted-foreground)" }}
            >
              {formatCompactNumber(value)}
            </text>
          </g>
        ))}

        {points.map((point, index) => {
          const groupX = PADDING.left + index * groupWidth;
          const centerX = groupX + groupWidth / 2;
          const revenue = toDisplayNumber(point.revenue);
          const expenses = toDisplayNumber(point.expenses);
          const revenueHeight = (revenue / maximum) * PLOT_HEIGHT;
          const expensesHeight = (expenses / maximum) * PLOT_HEIGHT;
          const revenueX = centerX - barWidth - BAR_GAP / 2;
          const expensesX = centerX + BAR_GAP / 2;

          return (
            <g key={point.key}>
              <rect
                x={revenueX}
                y={baseline - revenueHeight}
                width={barWidth}
                height={Math.max(0, revenueHeight)}
                rx={2}
                style={{ fill: "var(--primary)" }}
              >
                <title>{`${point.label} — Revenue ${formatMoneyValue(
                  point.revenue,
                  currency,
                )}`}</title>
              </rect>
              <rect
                x={expensesX}
                y={baseline - expensesHeight}
                width={barWidth}
                height={Math.max(0, expensesHeight)}
                rx={2}
                style={{ fill: "var(--info)" }}
              >
                <title>{`${point.label} — Expenses ${formatMoneyValue(
                  point.expenses,
                  currency,
                )}`}</title>
              </rect>
              <text
                x={centerX}
                y={HEIGHT - PADDING.bottom + 18}
                textAnchor="middle"
                fontSize={11}
                style={{ fill: "var(--muted-foreground)" }}
              >
                {shortLabel(point.label)}
              </text>
            </g>
          );
        })}

        <line
          x1={PADDING.left}
          x2={WIDTH - PADDING.right}
          y1={baseline}
          y2={baseline}
          style={{ stroke: "var(--border-strong)" }}
          strokeWidth={1}
        />
      </svg>

      <figcaption className="sr-only">
        {points
          .map(
            (point) =>
              `${point.label}: revenue ${formatMoneyValue(
                point.revenue,
                currency,
              )}, expenses ${formatMoneyValue(point.expenses, currency)}.`,
          )
          .join(" ")}
      </figcaption>
    </figure>
  );
}
