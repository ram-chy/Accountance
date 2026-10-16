import { redirect } from "next/navigation";

import { getSession } from "@/lib/auth/session";

import { ForgotPasswordForm } from "./forgot-password-form";

export const metadata = {
  title: "Forgot password",
};

/** Per-request cookie read blocks rather than prerender. */
export const instant = false;

/**
 * Request-a-reset-link screen.
 *
 * A signed-in visitor has no reason to be here (they can change the password
 * from Settings → Security), so they are sent to the app root. The form itself
 * talks to the BFF, which never reveals whether an address is registered.
 */
export default async function ForgotPasswordPage() {
  if (await getSession()) {
    redirect("/");
  }

  return <ForgotPasswordForm />;
}
