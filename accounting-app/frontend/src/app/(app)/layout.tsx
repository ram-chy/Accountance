import { redirect } from "next/navigation";
import type { ReactNode } from "react";

import { AppShell } from "@/components/app/app-shell";
import { getSession } from "@/lib/auth/session";

/**
 * The authenticated shell reads the session cookie on every request, so this
 * segment blocks server-side instead of shipping a static shell.
 */
export const instant = false;

/**
 * Authenticated route group.
 *
 * Defence in depth alongside `proxy.ts`: the encrypted cookie is validated on
 * the server before any protected markup is produced. `AuthProvider` lives in
 * the root layout, so the client snapshot is shared across the app.
 */
export default async function AppLayout({ children }: { children: ReactNode }) {
  const session = await getSession();

  if (!session) {
    redirect("/login");
  }

  return <AppShell>{children}</AppShell>;
}
