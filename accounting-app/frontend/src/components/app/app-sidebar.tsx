"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { NAV_SECTIONS } from "@/lib/navigation/nav";
import { cn } from "@/lib/utils/cn";

/**
 * Primary navigation.
 *
 * Rendered inside the desktop sidebar and, unchanged, inside the mobile drawer.
 * Implemented destinations are permission-filtered links; planned modules are
 * shown as disabled placeholders so the structure is visible without offering a
 * link to a page that does not exist yet.
 */
export function AppSidebar({
  id,
  onNavigate,
}: {
  id?: string;
  onNavigate?: () => void;
}) {
  const { can } = usePermissions();
  const pathname = usePathname();

  return (
    <nav
      id={id}
      aria-label="Primary"
      className="flex h-full flex-col gap-6 overflow-y-auto p-4"
    >
      <Link
        href="/"
        onClick={onNavigate}
        className="flex items-center gap-2 rounded-md px-1 py-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
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

      <div className="flex flex-col gap-5">
        {NAV_SECTIONS.map((section) => {
          const items = section.items.filter(
            (item) => !item.permission || can(item.permission),
          );
          const upcoming = section.upcoming ?? [];

          if (items.length === 0 && upcoming.length === 0) {
            return null;
          }

          return (
            <div key={section.label} className="flex flex-col gap-1">
              <p className="px-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {section.label}
              </p>

              {items.map((item) => {
                const active =
                  item.href === "/"
                    ? pathname === "/"
                    : pathname.startsWith(item.href);

                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    onClick={onNavigate}
                    aria-current={active ? "page" : undefined}
                    className={cn(
                      "rounded-md px-2 py-1.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
                      active
                        ? "bg-surface-muted text-foreground"
                        : "text-muted-foreground hover:bg-surface-muted hover:text-foreground",
                    )}
                  >
                    {item.label}
                  </Link>
                );
              })}

              {upcoming.map((label) => (
                <span
                  key={label}
                  aria-disabled="true"
                  className="flex items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm text-muted-foreground/70"
                >
                  {label}
                  <Badge variant="neutral">Soon</Badge>
                </span>
              ))}
            </div>
          );
        })}
      </div>
    </nav>
  );
}
