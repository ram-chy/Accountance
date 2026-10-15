import type { Metadata } from "next";

import { BudgetsView } from "@/components/budgets/budgets-view";

export const metadata: Metadata = {
  title: "Budgets",
};

/**
 * Budgets route.
 *
 * The `(app)` layout already validates the session cookie and blocks rendering
 * without it, so this page only hosts the client view. Budgets are loaded
 * client-side through the proxy via `BudgetsView`, keeping one
 * session/company/permission flow for the whole app.
 */
export default function BudgetsPage() {
  return <BudgetsView />;
}
