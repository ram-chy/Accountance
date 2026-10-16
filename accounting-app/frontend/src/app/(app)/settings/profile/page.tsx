import type { Metadata } from "next";

import { ProfileView } from "@/components/settings/profile-view";

export const metadata: Metadata = {
  title: "My profile",
};

/**
 * Profile route.
 *
 * The `(app)` layout validates the session cookie before this renders; the view
 * reads the already-loaded session snapshot from `AuthProvider`, so no extra
 * fetch is needed here.
 */
export default function ProfilePage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">My profile</h1>
        <p className="text-sm text-muted-foreground">
          Your account details and roles.
        </p>
      </div>

      <ProfileView />
    </div>
  );
}
