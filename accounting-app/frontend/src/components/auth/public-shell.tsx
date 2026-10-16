import type { ReactNode } from "react";

import { ThemeToggle } from "@/components/theme/theme-toggle";

/**
 * Shared chrome for the unauthenticated account-recovery screens
 * (forgot password, reset password).
 *
 * It mirrors the sign-in screen's layout — a theme toggle pinned top-right and
 * a centred column — without extracting the sign-in page itself, which keeps
 * this change contained to the new routes. It deliberately holds no form state
 * or session logic: each page owns its own behaviour.
 */
export function PublicShell({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle: string;
  children: ReactNode;
}) {
  return (
    <div className="flex min-h-dvh flex-col">
      <div className="flex justify-end p-4">
        <ThemeToggle />
      </div>

      <div className="flex flex-1 items-center justify-center px-4 pb-16">
        <div className="w-full max-w-sm">
          <div className="mb-6 flex flex-col items-center gap-2 text-center">
            <span
              aria-hidden="true"
              className="flex h-11 w-11 items-center justify-center rounded-lg bg-primary text-lg font-semibold text-on-primary"
            >
              A
            </span>
            <h1 className="text-lg font-semibold text-foreground">{title}</h1>
            <p className="text-sm text-muted-foreground">{subtitle}</p>
          </div>

          {children}
        </div>
      </div>
    </div>
  );
}
