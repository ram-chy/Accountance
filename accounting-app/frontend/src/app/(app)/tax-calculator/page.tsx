import type { Metadata } from "next";

import { TaxCalculatorView } from "@/components/taxes/tax-calculator-view";

export const metadata: Metadata = {
  title: "Tax calculator",
};

/**
 * Tax calculator route.
 *
 * The `(app)` layout already validates the session cookie, so this page only
 * hosts the client view. The calculator posts to a read-only endpoint through the
 * proxy; it persists nothing.
 */
export default function TaxCalculatorPage() {
  return <TaxCalculatorView />;
}
