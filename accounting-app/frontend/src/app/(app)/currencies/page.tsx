import type { Metadata } from "next";

import { CurrenciesView } from "@/components/currencies/currencies-view";

export const metadata: Metadata = {
  title: "Currencies",
};

/**
 * Currencies route.
 *
 * The `(app)` layout already validates the session cookie and blocks rendering
 * without it, so this page only hosts the client view. Currencies are loaded
 * client-side through the proxy via `CurrenciesView`, keeping one
 * session/company/permission flow for the whole app.
 */
export default function CurrenciesPage() {
  return <CurrenciesView />;
}
