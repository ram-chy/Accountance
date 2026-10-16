"use client";

import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";

import { useAuth } from "@/components/auth/auth-provider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ApiError } from "@/lib/api/errors";
import { resendVerificationEmail } from "@/lib/auth/client";
import { formatTimestamp } from "@/lib/format/date";

/**
 * The signed-in user's own account.
 *
 * This is deliberately read-only. The API exposes no self-service profile
 * update — `PUT /users/{id}` requires `users.update`, which only an Admin holds
 * — so offering an edit form here would imply a capability the backend does not
 * grant. Name, email and role changes are therefore an administrator action
 * (Settings → Users); the only self-service mutation is the password, which
 * lives on the Security screen.
 *
 * The one self-service action offered is resending the verification email,
 * which the current user is always allowed to trigger for themselves.
 */
export function ProfileView() {
  const { user, refresh } = useAuth();
  const [sending, setSending] = useState(false);

  if (!user) {
    return (
      <EmptyState
        title="No profile to show"
        description="Your account details could not be loaded. Reload the page to try again."
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
    <section className="flex flex-col gap-6 rounded-lg border border-border bg-surface p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-sm font-semibold text-foreground">Account details</h2>
        <Badge variant={user.email_verified ? "success" : "warning"}>
          {user.email_verified ? "Email verified" : "Email not verified"}
        </Badge>
      </div>

      {!user.email_verified ? (
        <div className="flex flex-col gap-3 rounded-md border border-warning-border bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground sm:flex-row sm:items-center sm:justify-between">
          <span>Verify your email address to secure your account.</span>
          <Button
            variant="secondary"
            size="sm"
            onClick={resend}
            loading={sending}
          >
            {sending ? "Sending…" : "Resend verification email"}
          </Button>
        </div>
      ) : null}

      <dl className="grid gap-4 sm:grid-cols-2">
        <Detail label="Full name" value={user.full_name} />
        <Detail label="Email" value={user.email} />
        <Detail label="Mobile" value={user.mobile_no ?? "—"} />
        <Detail
          label="Roles"
          value={user.roles.map((role) => role.name).join(", ") || "—"}
        />
        <Detail
          label="Last sign-in"
          value={formatTimestamp(user.last_login_at)}
        />
      </dl>

      <div className="flex flex-wrap items-center gap-3 border-t border-border pt-4">
        <p className="text-xs text-muted-foreground">
          Profile details are managed by an administrator. You can{" "}
          <Link
            href="/settings/security"
            className="font-medium text-primary-strong underline underline-offset-2"
          >
            change your password
          </Link>{" "}
          from the Security screen.
        </p>
      </div>
    </section>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
        {label}
      </dt>
      <dd className="text-sm text-foreground">{value}</dd>
    </div>
  );
}
