import type { Metadata } from "next";

import { TaxReportsView } from "@/components/taxes/tax-reports-view";

export const metadata: Metadata = {
  title: "Tax reports",
};

/**
 * Tax reports route.
 *
 * The `(app)` layout already validates the session cookie, so this page only
 * hosts the client view. Both reports are read-only and loaded client-side
 * through the proxy via `TaxReportsView`.
 */
export default function TaxReportsPage() {
  return <TaxReportsView />;
}
