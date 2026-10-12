import { cn } from "@/lib/utils/cn";

/** Loading placeholder for the tax detail screen. */

function SkeletonBlock({ className }: { className?: string }) {
  return (
    <div
      aria-hidden="true"
      className={cn("animate-pulse rounded bg-surface-muted", className)}
    />
  );
}

export function TaxDetailSkeleton() {
  return (
    <div role="status" aria-live="polite" className="flex flex-col gap-6">
      <span className="sr-only">Loading tax</span>

      <div className="flex flex-col gap-2">
        <SkeletonBlock className="h-6 w-48" />
        <SkeletonBlock className="h-4 w-72" />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <SkeletonBlock className="h-32 w-full rounded-lg" />
        <SkeletonBlock className="h-32 w-full rounded-lg" />
      </div>

      <SkeletonBlock className="h-40 w-full rounded-lg" />
    </div>
  );
}
