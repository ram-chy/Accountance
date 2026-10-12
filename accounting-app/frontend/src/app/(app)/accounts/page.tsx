import type { Metadata } from "next";

import { AccountsView } from "@/components/accounts/accounts-view";

export const metadata: Metadata = {
  title: "Chart of accounts",
};

/**
 * Chart of accounts route.
 *
 * The `(app)` layout already validates the session cookie and blocks rendering
 * without it, so this page only hosts the client view. Accounts are loaded
 * client-side through the Phase 3 proxy via `AccountsView`, keeping one
 * session/company/permission flow for the whole app.
 */
export default function AccountsPage() {
  return <AccountsView />;
}