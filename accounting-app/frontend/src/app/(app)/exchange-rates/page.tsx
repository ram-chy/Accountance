import type { Metadata } from "next";

import { ExchangeRatesView } from "@/components/exchange-rates/exchange-rates-view";

export const metadata: Metadata = {
  title: "Exchange rates",
};

/**
 * Exchange rates route.
 *
 * The `(app)` layout already validates the session cookie and blocks rendering
 * without it, so this page only hosts the client view. Rates are loaded
 * client-side through the proxy via `ExchangeRatesView`, keeping one
 * session/company/permission flow for the whole app.
 */
export default function ExchangeRatesPage() {
  return <ExchangeRatesView />;
}
