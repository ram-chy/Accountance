import { cn } from "@/lib/utils/cn";
import type { ComponentProps } from "react";

export type BadgeVariant =
  | "neutral"
  | "primary"
  | "success"
  | "warning"
  | "danger"
  | "info";

type BadgeProps = ComponentProps<"span"> & {
  variant?: BadgeVariant;
};

const variantClasses: Record<BadgeVariant, string> = {
  neutral: "border-border bg-surface-muted text-muted-foreground",
  primary: "border-primary/30 bg-primary-subtle text-primary-strong",
  success: "border-success-border bg-success-soft text-success-soft-foreground",
  warning: "border-warning-border bg-warning-soft text-warning-soft-foreground",
  danger: "border-danger-border bg-danger-soft text-danger-soft-foreground",
  info: "border-info-border bg-info-soft text-info-soft-foreground",
};

export function Badge({ variant = "neutral", className, ...props }: BadgeProps) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium",
        variantClasses[variant],
        className,
      )}
      {...props}
    />
  );
}
