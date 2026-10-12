import { redirect } from "next/navigation";

import { sanitizeNextPath } from "@/lib/auth/paths";
import { getSession } from "@/lib/auth/session";

import { LoginForm } from "./login-form";

export const metadata = {
  title: "Sign in",
};

type LoginPageProps = {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
};

/** Per-request cookie + searchParams reads block rather than prerender. */
export const instant = false;

/**
 * Sign-in screen.
 *
 * Already-authenticated visitors are bounced straight through to `next` (or the
 * app root). The cookie is validated server-side, so this redirect needs no
 * client round-trip.
 */
export default async function LoginPage({ searchParams }: LoginPageProps) {
  const [session, params] = await Promise.all([getSession(), searchParams]);
  const next = sanitizeNextPath(params.next);

  if (session) {
    redirect(next);
  }

  return <LoginForm nextPath={next} />;
}
