import type { Metadata } from "next";

import { UsersView } from "@/components/users/users-view";

export const metadata: Metadata = {
  title: "Users",
};

/**
 * User administration route.
 *
 * Users are global (not company-scoped); access is gated on `users.view` in the
 * view and re-authorised by the backend on every call.
 */
export default function UsersPage() {
  return <UsersView />;
}
