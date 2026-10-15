import { cn } from "@/lib/utils/cn";

/**
 * Loading placeholder for the currency settings screen.
 *
 * Sized to the form it stands in for: a heading line, an explanatory paragraph
 * and two side-by-side account pickers.
 */

function SkeletonBlock({ className }: { className?: string }) {
  return (
    <div
      aria-hidden="true"
      className={cn("animate-pulse rounded bg-surface-muted", className)}
    />
  );
}

export function FxSettingsSkeleton() {
  return (
    <div role="status" aria-live="polite" className="flex flex-col gap-4">
      <span className="sr-only">Loading currency settings</span>

      <SkeletonBlock className="h-6 w-56" />
      <SkeletonBlock className="h-10 w-full" />

      <div className="grid gap-4 sm:grid-cols-2">
        <SkeletonBlock className="h-16 w-full" />
        <SkeletonBlock className="h-16 w-full" />
      </div>

      <SkeletonBlock className="h-10 w-40" />
    </div>
  );
}
