import type { Metadata } from "next";

import { FinancialYearsView } from "@/components/financial-years/financial-years-view";

export const metadata: Metadata = {
  title: "Financial years",
};

/**
 * Financial years route.
 *
 * The `(app)` layout already validates the session cookie and blocks rendering
 * without it, so this page only hosts the client view. Financial years are
 * loaded client-side through the proxy via `FinancialYearsView`, keeping one
 * session/company/permission flow for the whole app.
 */
export default function FinancialYearsPage() {
  return <FinancialYearsView />;
}
