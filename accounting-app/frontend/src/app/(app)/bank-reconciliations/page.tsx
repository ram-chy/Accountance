import type { Metadata } from "next";

import { BankReconciliationsView } from "@/components/bank-reconciliations/bank-reconciliations-view";

export const metadata: Metadata = {
  title: "Bank reconciliations",
};

/**
 * Bank reconciliations route.
 *
 * The `(app)` layout already validates the session cookie and blocks rendering
 * without it, so this page only hosts the client view. Reconciliations are
 * loaded client-side through the proxy via `BankReconciliationsView`, keeping
 * one session/company/permission flow for the whole app.
 */
export default function BankReconciliationsPage() {
  return <BankReconciliationsView />;
}
