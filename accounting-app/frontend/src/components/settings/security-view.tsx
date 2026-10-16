"use client";

import { useState } from "react";
import { toast } from "sonner";

import { useAuth } from "@/components/auth/auth-provider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ApiError } from "@/lib/api/errors";
import { resendVerificationEmail } from "@/lib/auth/client";

import { ChangePasswordForm } from "./change-password-form";

/**
 * Security screen.
 *
 * Two self-service actions are exposed: changing the password (via the BFF,
 * which re-seals the session cookie with the replacement token) and resending
 * the email-verification link. Both are allowed for any signed-in user because
 * the backend permits them for the account owner.
 */
export function SecurityView() {
  const { user, refresh } = useAuth();
  const [sending, setSending] = useState(false);

  if (!user) {
    return (
      <EmptyState
        title="No account to secure"
        description="Your account could not be loaded. Reload the page to try again."
      />
    );
  }

  const resend = async () => {
    if (sending) return;

    setSending(true);

    try {
      await resendVerificationEmail();
      toast.success("Verification email sent. Check your inbox.");
      await refresh();
    } catch (error) {
      toast.error(
        error instanceof ApiError
          ? error.message
          : "We could not send the verification email. Please try again.",
      );
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="flex flex-col gap-6">
      <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4 sm:p-6">
        <div className="flex flex-col gap-1">
          <h2 className="text-sm font-semibold text-foreground">Password</h2>
          <p className="text-sm text-muted-foreground">
            Changing your password signs out any other active sessions.
          </p>
        </div>

        <ChangePasswordForm />
      </section>

      <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4 sm:p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-sm font-semibold text-foreground">
            Email verification
          </h2>
          <Badge variant={user.email_verified ? "success" : "warning"}>
            {user.email_verified ? "Verified" : "Not verified"}
          </Badge>
        </div>

        <p className="text-sm text-muted-foreground">
          {user.email_verified
            ? `${user.email} is verified.`
            : `We have not verified ${user.email} yet. Resend the link and follow it to confirm your address.`}
        </p>

        {!user.email_verified ? (
          <div>
            <Button variant="secondary" onClick={resend} loading={sending}>
              {sending ? "Sending…" : "Resend verification email"}
            </Button>
          </div>
        ) : null}
      </section>
    </div>
  );
}
