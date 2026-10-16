"use client";

import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Input } from "@/components/ui/field";
import { ApiError } from "@/lib/api/errors";
import { formatTimestamp } from "@/lib/format/date";
import { deactivateUser } from "@/lib/users/api";
import type { User } from "@/lib/users/types";
import { FIXED_ROLES } from "@/lib/users/types";
import { useUserList } from "@/lib/users/use-user-list";

import { UserFormDialog } from "./user-form-dialog";
import { UsersListSkeleton } from "./users-list-skeleton";

/**
 * User administration.
 *
 * The users index is a single paginated endpoint whose response carries no
 * pagination metadata, so the hook reads the largest page the API allows
 * (`per_page: 100`) and this screen shows a "more users may exist" note rather
 * than a fabricated page count. Search, role and status filters are all applied
 * server-side.
 *
 * Roles are the fixed application set (`RoleName`); there is no role-management
 * API, so roles are only ever assigned to a user, never created or edited here.
 *
 * Every write is gated on the matching capability and the backend re-authorises
 * it. Deactivation is a soft state change on the server (never a delete), so it
 * is confirmed and then reflected by re-reading the list.
 */

const DEBOUNCE_MS = 300;

export function UsersView() {
  const { user: currentUser } = useAuth();
  const { can } = usePermissions();

  const canView = can("users.view");
  const canCreate = can("users.create");
  const canUpdate = can("users.update");
  const canDelete = can("users.delete");

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [role, setRole] = useState("");
  const [status, setStatus] = useState<"all" | "active" | "inactive">("all");
  const [reloadToken, setReloadToken] = useState(0);

  const [editor, setEditor] = useState<{ user: User | null; key: string } | null>(null);
  const [deactivateTarget, setDeactivateTarget] = useState<User | null>(null);
  const [deactivating, setDeactivating] = useState(false);
  const [deactivateError, setDeactivateError] = useState<string | null>(null);

  useEffect(() => {
    const handle = setTimeout(() => setSearch(searchInput.trim()), DEBOUNCE_MS);
    return () => clearTimeout(handle);
  }, [searchInput]);

  const filters = useMemo(
    () => ({
      search: search || undefined,
      role: role || undefined,
      is_active: status === "all" ? undefined : status === "active",
    }),
    [search, role, status],
  );

  const state = useUserList({ enabled: canView, filters, reloadToken });
  const reload = () => setReloadToken((token) => token + 1);

  const deactivate = async () => {
    if (deactivateTarget === null || deactivating) return;

    setDeactivating(true);
    setDeactivateError(null);

    try {
      await deactivateUser(deactivateTarget.id);
      toast.success(`${deactivateTarget.full_name} was deactivated.`);
      setDeactivateTarget(null);
      reload();
    } catch (error) {
      setDeactivateError(
        error instanceof ApiError
          ? error.message
          : "We could not deactivate the user. Please try again.",
      );
    } finally {
      setDeactivating(false);
    }
  };

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-xl font-semibold text-foreground">Users</h1>
          <p className="text-sm text-muted-foreground">
            Accounts that can sign in. A user account is distinct from a company
            membership.
          </p>
        </div>
        {canCreate ? (
          <Button onClick={() => setEditor({ user: null, key: "create" })}>
            New user
          </Button>
        ) : null}
      </div>

      {canView ? (
        <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end">
          <div className="flex flex-1 flex-col gap-1.5">
            <label htmlFor="users-search" className="text-sm font-medium text-foreground">
              Search
            </label>
            <Input
              id="users-search"
              value={searchInput}
              onChange={(event) => setSearchInput(event.target.value)}
              placeholder="Name or email"
              autoComplete="off"
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <label htmlFor="users-role" className="text-sm font-medium text-foreground">
              Role
            </label>
            <select
              id="users-role"
              className="h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
              value={role}
              onChange={(event) => setRole(event.target.value)}
            >
              <option value="">All roles</option>
              {FIXED_ROLES.map((entry) => (
                <option key={entry} value={entry}>
                  {entry}
                </option>
              ))}
            </select>
          </div>

          <div className="flex flex-col gap-1.5">
            <label htmlFor="users-status" className="text-sm font-medium text-foreground">
              Status
            </label>
            <select
              id="users-status"
              className="h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
              value={status}
              onChange={(event) =>
                setStatus(event.target.value as "all" | "active" | "inactive")
              }
            >
              <option value="all">All</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
      ) : null}

      {!canView ? (
        <EmptyState
          title="Users unavailable"
          description="Your role does not include access to user management."
        />
      ) : state.status === "loading" ? (
        <UsersListSkeleton />
      ) : state.status === "unavailable" ? (
        <EmptyState title="Users unavailable" description={state.message} />
      ) : state.status === "error" ? (
        <ErrorState
          title="Could not load users"
          message={state.message}
          requestId={state.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      ) : state.items.length === 0 ? (
        <EmptyState
          title="No users found"
          description={
            search || role || status !== "all"
              ? "No users match the current filters."
              : "There are no user accounts to display."
          }
        />
      ) : (
        <div className="flex flex-col gap-3">
          <UsersTable
            items={state.items}
            selfId={currentUser?.id ?? null}
            canUpdate={canUpdate}
            canDelete={canDelete}
            onEdit={(user) => setEditor({ user, key: `edit-${user.id}` })}
            onDeactivate={(user) => {
              setDeactivateError(null);
              setDeactivateTarget(user);
            }}
          />
          {state.hasMore ? (
            <p className="text-xs text-muted-foreground">
              Showing the first {state.items.length} users. More may exist — narrow
              the filters to find a specific account.
            </p>
          ) : null}
        </div>
      )}

      {editor ? (
        <UserFormDialog
          key={editor.key}
          open
          user={editor.user}
          selfId={currentUser?.id ?? null}
          onClose={() => setEditor(null)}
          onSaved={() => {
            setEditor(null);
            reload();
          }}
        />
      ) : null}

      <Dialog
        open={deactivateTarget !== null}
        title="Deactivate user"
        description={deactivateTarget ? `Deactivate ${deactivateTarget.full_name}?` : undefined}
        onClose={deactivating ? () => {} : () => setDeactivateTarget(null)}
        footer={
          <>
            <Button
              variant="ghost"
              onClick={() => setDeactivateTarget(null)}
              disabled={deactivating}
            >
              Cancel
            </Button>
            <Button variant="danger" onClick={deactivate} loading={deactivating}>
              Deactivate
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3 text-sm text-muted-foreground">
          {deactivateError ? (
            <ErrorState title="Could not deactivate" message={deactivateError} />
          ) : null}
          <p>
            The user will no longer be able to sign in. Their account and history
            are kept and can be reactivated from this screen.
          </p>
        </div>
      </Dialog>
    </div>
  );
}

function UsersTable({
  items,
  selfId,
  canUpdate,
  canDelete,
  onEdit,
  onDeactivate,
}: {
  items: User[];
  selfId: number | null;
  canUpdate: boolean;
  canDelete: boolean;
  onEdit: (user: User) => void;
  onDeactivate: (user: User) => void;
}) {
  return (
    <div className="overflow-x-auto rounded-lg border border-border bg-surface">
      <table className="w-full border-collapse text-left">
        <caption className="sr-only">Users</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Name
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Roles
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Status
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Last sign-in
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              <span className="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((user) => (
            <tr key={user.id} className="border-b border-border last:border-0">
              <td className="px-3 py-2 text-sm">
                <span className="font-medium text-foreground">{user.full_name}</span>
                <span className="block text-xs text-muted-foreground">{user.email}</span>
              </td>
              <td className="px-3 py-2 text-sm text-foreground">
                {user.roles.length > 0 ? user.roles.map((role) => role.name).join(", ") : "—"}
              </td>
              <td className="px-3 py-2">
                <Badge variant={user.is_active ? "success" : "neutral"}>
                  {user.is_active ? "Active" : "Inactive"}
                </Badge>
              </td>
              <td className="px-3 py-2 text-sm text-muted-foreground">
                {formatTimestamp(user.last_login_at)}
              </td>
              <td className="px-3 py-2">
                <div className="flex flex-wrap items-center justify-end gap-1.5">
                  {canUpdate ? (
                    <Button size="sm" variant="secondary" onClick={() => onEdit(user)}>
                      Edit
                    </Button>
                  ) : null}
                  {canDelete && user.is_active && user.id !== selfId ? (
                    <Button size="sm" variant="ghost" onClick={() => onDeactivate(user)}>
                      Deactivate
                    </Button>
                  ) : null}
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
