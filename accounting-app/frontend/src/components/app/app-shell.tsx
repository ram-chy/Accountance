"use client";

import { useEffect, useState, type ReactNode } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { Button } from "@/components/ui/button";
import { ErrorState } from "@/components/ui/error-state";
import { LoadingState } from "@/components/ui/loading-state";

import { AppHeader } from "./app-header";
import { AppSidebar } from "./app-sidebar";

/**
 * Authenticated shell: gates children on the client session and frames pages
 * with a persistent sidebar and header.
 *
 * `(app)/layout.tsx` already validated the cookie server-side; this adds the
 * client-side snapshot (user, companies, permissions) the UI needs, plus a
 * recovery path if the session load failed for a transient reason.
 *
 * On desktop the sidebar is fixed; on smaller screens it becomes an off-canvas
 * drawer opened from the header, closed by Escape, a backdrop click, or any
 * navigation.
 */
export function AppShell({ children }: { children: ReactNode }) {
  const { status, error, refresh } = useAuth();
  const [navOpen, setNavOpen] = useState(false);

  useEffect(() => {
    if (!navOpen) {
      return;
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setNavOpen(false);
      }
    };

    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [navOpen]);

  if (status === "loading") {
    return (
      <div className="flex min-h-dvh items-center justify-center">
        <LoadingState label="Loading your workspace…" />
      </div>
    );
  }

  if (status === "unauthenticated") {
    return (
      <div className="mx-auto flex min-h-dvh w-full max-w-md flex-col items-center justify-center gap-4 px-4">
        <ErrorState
          title="Session unavailable"
          message={error ?? "Your session has ended. Please sign in again."}
          action={
            <Button variant="secondary" onClick={() => void refresh()}>
              Try again
            </Button>
          }
        />
      </div>
    );
  }

  return (
    <div className="flex min-h-dvh bg-background">
      <aside className="hidden w-64 shrink-0 border-r border-border bg-surface lg:block">
        <AppSidebar />
      </aside>

      {navOpen ? (
        <div className="fixed inset-0 z-40 lg:hidden">
          <button
            type="button"
            aria-label="Close navigation"
            onClick={() => setNavOpen(false)}
            className="absolute inset-0 h-full w-full bg-black/40"
          />
          <div className="absolute inset-y-0 left-0 w-72 max-w-[80vw] border-r border-border bg-surface shadow-xl">
            <AppSidebar
              id="app-sidebar"
              onNavigate={() => setNavOpen(false)}
            />
          </div>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-1 flex-col">
        <AppHeader
          onOpenNavigation={() => setNavOpen(true)}
          navigationOpen={navOpen}
        />
        <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6 lg:px-8">
          {children}
        </main>
      </div>
    </div>
  );
}
