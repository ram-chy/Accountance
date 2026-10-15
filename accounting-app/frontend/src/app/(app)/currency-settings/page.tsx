import type { Metadata } from "next";

import { FxSettingsView } from "@/components/fx-settings/fx-settings-view";

export const metadata: Metadata = {
  title: "Currency settings",
};

/**
 * Currency settings route.
 *
 * The `(app)` layout already validates the session cookie and blocks rendering
 * without it, so this page only hosts the client view. The setting is loaded
 * client-side through the proxy via `FxSettingsView`, keeping one
 * session/company/permission flow for the whole app.
 */
export default function CurrencySettingsPage() {
  return <FxSettingsView />;
}
