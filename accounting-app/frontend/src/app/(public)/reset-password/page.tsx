import Link from "next/link";

import { PublicShell } from "@/components/auth/public-shell";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";

import { ResetPasswordForm } from "./reset-password-form";

export const metadata = {
  title: "Reset password",
};

/** Per-request searchParams read blocks rather than prerender. */
export const instant = false;

type ResetPasswordPageProps = {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
};

function first(value: string | string[] | undefined): string {
  return (Array.isArray(value) ? value[0] : value) ?? "";
}

/**
 * Reset-password screen reached from the emailed link.
 *
 * The link carries `token` and `email`. When either is missing there is nothing
 * to validate, so a self-contained "link not usable" card is shown instead of a
 * form. When both are present the form checks the token with the BFF before
 * offering the password fields.
 */
export default async function ResetPasswordPage({
  searchParams,
}: ResetPasswordPageProps) {
  const params = await searchParams;
  const token = first(params.token);
  const email = first(params.email);

  if (token === "" || email === "") {
    return (
      <PublicShell
        title="Reset your password"
        subtitle="Choose a new password for your account."
      >
        <Card>
          <CardContent>
            <EmptyState
              title="This reset link is not usable"
              description="The link is incomplete. Request a new password reset email and open the link from that message."
              action={
                <Link
                  href="/forgot-password"
                  className="text-sm font-medium text-primary-strong underline underline-offset-2"
                >
                  Request a new link
                </Link>
              }
            />
          </CardContent>
        </Card>
      </PublicShell>
    );
  }

  return (
    <PublicShell
      title="Reset your password"
      subtitle="Choose a new password for your account."
    >
      <Card>
        <CardHeader>
          <CardTitle>Choose a new password</CardTitle>
          <CardDescription>
            Resetting your password signs out any other active sessions.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <ResetPasswordForm token={token} email={email} />
        </CardContent>
      </Card>
    </PublicShell>
  );
}
