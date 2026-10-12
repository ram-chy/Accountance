import { cn } from "@/lib/utils/cn";

/** Low-emphasis placeholder used while a dashboard slice is loading. */
export function SkeletonBlock({ className }: { className?: string }) {
  return (
    <div
      aria-hidden="true"
      className={cn("animate-pulse rounded bg-surface-muted", className)}
    />
  );
}

/** Placeholder sized like a summary-card value. */
export function SummaryValueSkeleton() {
  return <SkeletonBlock className="h-7 w-28" />;
}

const SKELETON_BAR_HEIGHTS = [40, 64, 52, 78, 60, 88, 46, 70];

/** Placeholder for a chart region. */
export function ChartSkeleton() {
  return (
    <div
      role="status"
      aria-live="polite"
      className="flex h-56 w-full items-end gap-3 px-2 pb-2"
    >
      <span className="sr-only">Loading chart</span>
      {SKELETON_BAR_HEIGHTS.map((height, index) => (
        <div key={index} className="flex flex-1 justify-center">
          <div
            aria-hidden="true"
            className="w-full max-w-6 animate-pulse rounded bg-surface-muted"
            style={{ height: `${height}%` }}
          />
        </div>
      ))}
    </div>
  );
}

/** Placeholder rows for the activity table. */
export function TableSkeleton({ rows = 5 }: { rows?: number }) {
  return (
    <div role="status" aria-live="polite" className="flex flex-col gap-3 py-1">
      <span className="sr-only">Loading activity</span>
      {Array.from({ length: rows }).map((_, index) => (
        <SkeletonBlock key={index} className="h-9 w-full" />
      ))}
    </div>
  );
}
