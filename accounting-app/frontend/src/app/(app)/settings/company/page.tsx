import type { Metadata } from "next";

import { CompanyView } from "@/components/company/company-view";

export const metadata: Metadata = {
  title: "Company settings",
};

/**
 * Company administration route for the active company.
 *
 * The `(app)` layout validates the session cookie and supplies the session
 * snapshot; `CompanyView` reads the active company from `AuthProvider` and loads
 * the company-scoped settings through the proxy. Writes are capability-gated in
 * the view and re-authorised by the backend.
 */
export default function CompanySettingsPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">Company settings</h1>
        <p className="text-sm text-muted-foreground">
          Profile, numbering and document defaults for the active company.
        </p>
      </div>

      <CompanyView />
    </div>
  );
}
