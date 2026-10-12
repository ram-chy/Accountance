import { cn } from "@/lib/utils/cn";

/**
 * Loading placeholder for the financial-years list.
 *
 * Mirrors the table layout on medium+ screens and the stacked cards below, using
 * the same low-emphasis pulse as the other list skeletons. The `role="status"`
 * live region keeps assistive technology informed while the rows stream in.
 */

function SkeletonBlock({ className }: { className?: string }) {
  return (
    <div
      aria-hidden="true"
      className={cn("animate-pulse rounded bg-surface-muted", className)}
    />
  );
}

export function FinancialYearsListSkeleton({ rows = 6 }: { rows?: number }) {
  return (
    <div role="status" aria-live="polite" className="flex flex-col gap-3">
      <span className="sr-only">Loading financial years</span>

      <div className="hidden flex-col gap-3 md:flex">
        {Array.from({ length: rows }).map((_, index) => (
          <SkeletonBlock key={index} className="h-10 w-full" />
        ))}
      </div>

      <div className="flex flex-col gap-3 md:hidden">
        {Array.from({ length: 4 }).map((_, index) => (
          <SkeletonBlock key={index} className="h-32 w-full rounded-lg" />
        ))}
      </div>
    </div>
  );
}
