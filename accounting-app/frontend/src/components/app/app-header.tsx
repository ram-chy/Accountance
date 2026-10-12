"use client";

import Link from "next/link";

import { ThemeToggle } from "@/components/theme/theme-toggle";

import { CompanySwitcher } from "./company-switcher";
import { UserMenu } from "./user-menu";

/**
 * Authenticated top bar.
 *
 * Holds the mobile navigation trigger, the brand (mobile only — the desktop
 * sidebar carries it), the active-company selector, the theme toggle and the
 * account menu. Primary navigation lives in `AppSidebar`.
 */
export function AppHeader({
  onOpenNavigation,
  navigationOpen,
}: {
  onOpenNavigation: () => void;
  navigationOpen: boolean;
}) {
  return (
    <header className="sticky top-0 z-30 border-b border-border bg-surface/80 backdrop-blur">
      <div className="flex h-14 w-full items-center gap-3 px-4 sm:px-6 lg:px-8">
        <button
          type="button"
          onClick={onOpenNavigation}
          aria-label="Open navigation"
          aria-controls="app-sidebar"
          aria-expanded={navigationOpen}
          className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border bg-surface text-foreground transition-colors hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:hidden"
        >
          <span aria-hidden="true" className="flex flex-col gap-1">
            <span className="block h-0.5 w-4 bg-current" />
            <span className="block h-0.5 w-4 bg-current" />
            <span className="block h-0.5 w-4 bg-current" />
          </span>
        </button>

        <Link
          href="/"
          className="flex items-center gap-2 rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:hidden"
        >
          <span
            aria-hidden="true"
            className="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-sm font-semibold text-on-primary"
          >
            A
          </span>
          <span className="text-sm font-semibold text-foreground">
            Accountance
          </span>
        </Link>

        <div className="ml-auto flex items-center gap-2">
          <CompanySwitcher />
          <ThemeToggle />
          <UserMenu />
        </div>
      </div>
    </header>
  );
}
