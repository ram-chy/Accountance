import type { Metadata } from "next";

import { DimensionsView } from "@/components/dimensions/dimensions-view";

export const metadata: Metadata = {
  title: "Dimensions",
};

/**
 * Dimensions route.
 *
 * The `(app)` layout already validates the session cookie and blocks rendering
 * without it, so this page only hosts the client view. Dimensions are loaded
 * client-side through the proxy via `DimensionsView`, keeping one
 * session/company/permission flow for the whole app.
 */
export default function DimensionsPage() {
  return <DimensionsView />;
}
