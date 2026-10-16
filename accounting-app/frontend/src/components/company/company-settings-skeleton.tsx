import { cn } from "@/lib/utils/cn";

/**
 * Loading placeholder for the company settings section.
 *
 * Sized to the settings form it stands in for: a heading and a two-column grid
 * of fields with a submit row.
 */

function SkeletonBlock({ className }: { className?: string }) {
  return (
    <div
      aria-hidden="true"
      className={cn("animate-pulse rounded bg-surface-muted", className)}
    />
  );
}

export function CompanySettingsSkeleton() {
  return (
    <div role="status" aria-live="polite" className="flex flex-col gap-4">
      <span className="sr-only">Loading company settings</span>

      <SkeletonBlock className="h-6 w-48" />

      <div className="grid gap-4 sm:grid-cols-2">
        <SkeletonBlock className="h-16 w-full" />
        <SkeletonBlock className="h-16 w-full" />
        <SkeletonBlock className="h-16 w-full" />
        <SkeletonBlock className="h-16 w-full" />
      </div>

      <SkeletonBlock className="h-10 w-40" />
    </div>
  );
}
