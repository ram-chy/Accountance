import { cn } from "@/lib/utils/cn";
import type { ComponentProps, ReactNode } from "react";

/** Vertical stack for a label, a control, and its helper/error text. */
export function Field({ className, ...props }: ComponentProps<"div">) {
  return <div className={cn("flex flex-col gap-1.5", className)} {...props} />;
}

export function Label({ className, ...props }: ComponentProps<"label">) {
  return (
    <label
      className={cn(
        "text-sm font-medium text-foreground",
        "has-[:disabled]:text-muted-foreground",
        className,
      )}
      {...props}
    />
  );
}

type InputProps = ComponentProps<"input"> & {
  /** Applies the invalid treatment and sets aria-invalid. */
  invalid?: boolean;
};

export function Input({ className, invalid, "aria-invalid": ariaInvalid, ...props }: InputProps) {
  return (
    <input
      aria-invalid={invalid || ariaInvalid || undefined}
      className={cn(
        "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground",
        "placeholder:text-muted-foreground",
        "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
        "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground",
        invalid && "border-danger",
        className,
      )}
      {...props}
    />
  );
}

export function HelperText({ className, ...props }: ComponentProps<"p">) {
  return (
    <p className={cn("text-xs text-muted-foreground", className)} {...props} />
  );
}

export function FieldError({ className, children, ...props }: ComponentProps<"p">) {
  if (!children) {
    return null;
  }

  return (
    <p className={cn("text-xs font-medium text-danger", className)} {...props}>
      {children}
    </p>
  );
}

/** Convenience wrapper: labelled field with optional hint and error. */
export function FormField({
  label,
  htmlFor,
  hint,
  error,
  required,
  children,
}: {
  label: string;
  htmlFor: string;
  hint?: ReactNode;
  error?: ReactNode;
  required?: boolean;
  children: ReactNode;
}) {
  const hintId = hint ? `${htmlFor}-hint` : undefined;
  const errorId = error ? `${htmlFor}-error` : undefined;

  return (
    <Field>
      <Label htmlFor={htmlFor}>
        {label}
        {required ? (
          <span aria-hidden="true" className="ml-0.5 text-danger">
            *
          </span>
        ) : null}
      </Label>
      {children}
      {hint ? <HelperText id={hintId}>{hint}</HelperText> : null}
      {error ? (
        <FieldError id={errorId} role="alert">
          {error}
        </FieldError>
      ) : null}
    </Field>
  );
}
