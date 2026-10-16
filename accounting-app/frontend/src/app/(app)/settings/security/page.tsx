import type { Metadata } from "next";

import { SecurityView } from "@/components/settings/security-view";

export const metadata: Metadata = {
  title: "Security",
};

/**
 * Security route.
 *
 * Hosts the self-service password change and email-verification actions. The
 * `(app)` layout guarantees a session before this renders.
 */
export default function SecurityPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold text-foreground">Security</h1>
        <p className="text-sm text-muted-foreground">
          Manage your password and email verification.
        </p>
      </div>

      <SecurityView />
    </div>
  );
}
