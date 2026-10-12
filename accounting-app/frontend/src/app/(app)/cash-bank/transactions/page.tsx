import type { Metadata } from "next";
import { Suspense } from "react";

import { CashBankTransactionsView } from "@/components/cash-bank/cash-bank-transactions-view";

export const metadata: Metadata = {
  title: "Cash and bank transactions",
};

/**
 * Cash & bank transactions route.
 *
 * `CashBankTransactionsView` reads the optional `account_id` query parameter (so
 * the overview's per-account link can seed the filter), which requires a Suspense
 * boundary under the App Router. The fallback is intentionally empty — the view
 * renders its own loading state once mounted.
 */
export default function CashBankTransactionsPage() {
  return (
    <Suspense fallback={null}>
      <CashBankTransactionsView />
    </Suspense>
  );
}
