import type { Metadata } from "next";

import { FixedAssetsView } from "@/components/fixed-assets/fixed-assets-view";

export const metadata: Metadata = {
  title: "Fixed assets",
};

/**
 * Fixed-asset register route.
 *
 * The `(app)` layout has already validated the session cookie, and the view
 * loads its data client-side through the proxy so one session/company/permission
 * flow covers the whole app.
 */
export default function FixedAssetsPage() {
  return <FixedAssetsView />;
}
