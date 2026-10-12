import type { Metadata } from "next";

import { TaxesView } from "@/components/taxes/taxes-view";

export const metadata: Metadata = {
  title: "Taxes",
};

/**
 * Taxes route.
 *
 * The `(app)` layout already validates the session cookie and blocks rendering
 * without it, so this page only hosts the client view. Taxes are loaded
 * client-side through the proxy via `TaxesView`, keeping one
 * session/company/permission flow for the whole app.
 */
export default function TaxesPage() {
  return <TaxesView />;
}
