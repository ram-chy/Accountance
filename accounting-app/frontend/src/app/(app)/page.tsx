import type { Metadata } from "next";

import { DashboardView } from "@/components/dashboard/dashboard-view";

export const metadata: Metadata = {
  title: "Dashboard",
};

/**
 * Authenticated home.
 *
 * The `(app)` layout already validates the session cookie and blocks rendering
 * without it, so this page only hosts the dashboard. All figures are loaded
 * client-side through the Phase 3 proxy via `DashboardView`, which keeps one
 * session/company/permission flow for the whole app.
 */
export default function HomePage() {
  return <DashboardView />;
}
