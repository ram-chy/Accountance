"use client";

import { useEffect, useRef, useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { Button } from "@/components/ui/button";
import { Spinner } from "@/components/ui/spinner";

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);

  if (parts.length === 0) {
    return "?";
  }

  const first = parts[0]?.[0] ?? "";
  const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? "") : "";

  return `${first}${last}`.toUpperCase();
}

/**
 * Account menu: shows who is signed in and offers sign-out.
 *
 * A small controlled popover (no dependency) that closes on Escape or an
 * outside click and returns focus to the trigger.
 */
export function UserMenu() {
  const { user, logout } = useAuth();
  const [open, setOpen] = useState(false);
  const [signingOut, setSigningOut] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    const onPointerDown = (event: MouseEvent) => {
      if (
        containerRef.current &&
        !containerRef.current.contains(event.target as Node)
      ) {
        setOpen(false);
      }
    };

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setOpen(false);
        triggerRef.current?.focus();
      }
    };

    document.addEventListener("mousedown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);

    return () => {
      document.removeEventListener("mousedown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

  if (!user) {
    return null;
  }

  const roleNames = (user.roles ?? []).map((role) => role.name);

  const handleLogout = async () => {
    setSigningOut(true);
    try {
      await logout();
    } finally {
      setSigningOut(false);
    }
  };

  return (
    <div ref={containerRef} className="relative">
      <button
        ref={triggerRef}
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-haspopup="menu"
        aria-expanded={open}
        className="inline-flex items-center gap-2 rounded-md border border-border bg-surface py-1 pl-1 pr-3 text-left transition-colors hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        <span
          aria-hidden="true"
          className="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-xs font-semibold text-on-primary"
        >
          {initials(user.full_name)}
        </span>
        <span className="hidden flex-col leading-tight sm:flex">
          <span className="text-sm font-medium text-foreground">
            {user.full_name}
          </span>
          <span className="text-xs text-muted-foreground">{user.email}</span>
        </span>
      </button>

      {open ? (
        <div
          role="menu"
          aria-label="Account menu"
          className="absolute right-0 z-50 mt-2 w-64 rounded-lg border border-border bg-surface p-1 shadow-lg"
        >
          <div className="flex flex-col gap-0.5 px-3 py-2">
            <span className="text-sm font-medium text-foreground">
              {user.full_name}
            </span>
            <span className="text-xs text-muted-foreground">{user.email}</span>
            {roleNames.length > 0 ? (
              <span className="mt-1 text-xs text-muted-foreground">
                {roleNames.join(", ")}
              </span>
            ) : null}
          </div>
          <div className="my-1 h-px bg-border" />
          <Button
            variant="ghost"
            size="sm"
            className="w-full justify-start"
            onClick={handleLogout}
            loading={signingOut}
            role="menuitem"
          >
            {signingOut ? "Signing out…" : "Sign out"}
          </Button>
        </div>
      ) : null}

      {signingOut ? (
        <span className="sr-only" role="status">
          <Spinner size="sm" /> Signing out
        </span>
      ) : null}
    </div>
  );
}
