import type { Metadata } from "next";

import { CashBankView } from "@/components/cash-bank/cash-bank-view";

export const metadata: Metadata = {
  title: "Cash and banking",
};

/**
 * Cash & banking overview route.
 *
 * The `(app)` layout validates the session cookie and blocks rendering without
 * it, so this page only hosts the client view. Accounts and balances are loaded
 * client-side through the Phase 3 proxy via `CashBankView`.
 */
export default function CashBankPage() {
  return <CashBankView />;
}
