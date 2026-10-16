import { cn } from "@/lib/utils/cn";

/** Loading placeholder for the users list. */

function SkeletonBlock({ className }: { className?: string }) {
  return (
    <div
      aria-hidden="true"
      className={cn("animate-pulse rounded bg-surface-muted", className)}
    />
  );
}

export function UsersListSkeleton() {
  return (
    <div role="status" aria-live="polite" className="flex flex-col gap-3">
      <span className="sr-only">Loading users</span>
      {Array.from({ length: 5 }).map((_, index) => (
        <SkeletonBlock key={index} className="h-12 w-full" />
      ))}
    </div>
  );
}
