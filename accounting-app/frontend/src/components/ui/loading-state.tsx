import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils/cn";

type LoadingStateProps = {
  label?: string;
  className?: string;
};

/** Centered busy indicator with a polite live region for screen readers. */
export function LoadingState({
  label = "Loading…",
  className,
}: LoadingStateProps) {
  return (
    <div
      role="status"
      aria-live="polite"
      className={cn(
        "flex items-center justify-center gap-3 px-6 py-10 text-sm text-muted-foreground",
        className,
      )}
    >
      <Spinner size="md" />
      <span>{label}</span>
    </div>
  );
}
