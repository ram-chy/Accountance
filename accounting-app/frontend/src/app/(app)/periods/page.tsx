import type { Metadata } from "next";

import { PeriodsView } from "@/components/periods/periods-view";

export const metadata: Metadata = {
  title: "Accounting periods",
};

/**
 * Accounting periods route.
 *
 * The `(app)` layout already validates the session cookie and blocks rendering
 * without it, so this page only hosts the client view. Periods are loaded
 * client-side through the proxy via `PeriodsView`, keeping one
 * session/company/permission flow for the whole app.
 */
export default function PeriodsPage() {
  return <PeriodsView />;
}
