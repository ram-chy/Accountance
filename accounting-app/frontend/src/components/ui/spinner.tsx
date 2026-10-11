import { cn } from "@/lib/utils/cn";

type SpinnerProps = {
  /** Visual size. */
  size?: "sm" | "md" | "lg";
  className?: string;
  /** Accessible label; omit when the spinner is purely decorative next to text. */
  label?: string;
};

const sizeClasses: Record<NonNullable<SpinnerProps["size"]>, string> = {
  sm: "h-4 w-4",
  md: "h-5 w-5",
  lg: "h-8 w-8",
};

/**
 * Loading indicator. Uses `currentColor`, so it inherits the surrounding text or
 * button colour and stays correct in both themes.
 */
export function Spinner({ size = "md", className, label }: SpinnerProps) {
  return (
    <span
      role={label ? "status" : undefined}
      aria-label={label}
      aria-hidden={label ? undefined : true}
      className="inline-flex items-center justify-center"
    >
      <svg
        className={cn("animate-spin", sizeClasses[size], className)}
        viewBox="0 0 24 24"
        fill="none"
        aria-hidden="true"
      >
        <circle
          cx="12"
          cy="12"
          r="9"
          stroke="currentColor"
          strokeWidth="3"
          className="opacity-25"
        />
        <path
          d="M21 12a9 9 0 0 0-9-9"
          stroke="currentColor"
          strokeWidth="3"
          strokeLinecap="round"
          className="opacity-90"
        />
      </svg>
    </span>
  );
}
