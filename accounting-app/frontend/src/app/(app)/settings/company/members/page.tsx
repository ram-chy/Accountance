import type { Metadata } from "next";

import { CompanyMembersView } from "@/components/company/company-members-view";

export const metadata: Metadata = {
  title: "Company members",
};

/**
 * Company membership route.
 *
 * The backend has no member-listing endpoint, so this page hosts the add/remove
 * workflow only and states that limitation inline rather than showing a roster.
 */
export default function CompanyMembersPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">Company members</h1>
        <p className="text-sm text-muted-foreground">
          Grant or revoke a user&#39;s access to the active company.
        </p>
      </div>

      <CompanyMembersView />
    </div>
  );
}
