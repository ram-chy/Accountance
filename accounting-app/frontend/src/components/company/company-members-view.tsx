"use client";

import { useMemo, useState } from "react";
import { toast } from "sonner";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Field, Label } from "@/components/ui/field";
import { ApiError } from "@/lib/api/errors";
import { addCompanyMember, removeCompanyMember } from "@/lib/company/api";
import type { User } from "@/lib/users/types";
import { useUserList } from "@/lib/users/use-user-list";

/**
 * Company membership management for the active company.
 *
 * The backend exposes only `POST /companies/{id}/members` and
 * `DELETE /companies/{id}/members/{user}` — there is **no** member-listing
 * endpoint — so this screen deliberately shows no roster (nothing is faked). It
 * offers two precise operations: add a user by picking them from the global
 * users list, and remove a user the same way. The server is the authority on
 * whether the user already belongs (`422`) or does not belong (`404`), and both
 * refusals are surfaced.
 *
 * Both actions require `companies.update` (membership + capability), enforced
 * server-side; `users.view` is additionally needed to populate the picker.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export function CompanyMembersView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();

  const canManage = can("companies.update");
  const canViewUsers = can("users.view");
  const companyId = activeCompany?.id ?? null;

  const users = useUserList({ enabled: canViewUsers, filters: {}, reloadToken: 0 });

  const [addUserId, setAddUserId] = useState("");
  const [removeUserId, setRemoveUserId] = useState("");
  const [adding, setAdding] = useState(false);
  const [removing, setRemoving] = useState(false);
  const [addError, setAddError] = useState<string | null>(null);
  const [removeError, setRemoveError] = useState<string | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);

  const userOptions = useMemo(
    () => (users.status === "ready" ? users.items : []),
    [users.status, users.items],
  );
  const usersReady = users.status === "ready";

  const selectedForRemoval: User | undefined = useMemo(
    () => userOptions.find((user) => String(user.id) === removeUserId),
    [userOptions, removeUserId],
  );

  if (!activeCompany || companyId === null) {
    return (
      <EmptyState
        title="No company selected"
        description="Choose a company to manage its members."
      />
    );
  }

  if (!canManage) {
    return (
      <EmptyState
        title="Members unavailable"
        description="Managing members requires the company update permission."
      />
    );
  }

  const labelFor = (user: User) =>
    `${user.full_name} (${user.email})${user.is_active ? "" : " — deactivated"}`;

  const add = async () => {
    if (adding || addUserId === "") return;

    setAdding(true);
    setAddError(null);

    try {
      await addCompanyMember(companyId, Number.parseInt(addUserId, 10));
      toast.success("User added to the company.");
      setAddUserId("");
    } catch (error) {
      setAddError(
        error instanceof ApiError
          ? error.message
          : "We could not add the user. Please try again.",
      );
    } finally {
      setAdding(false);
    }
  };

  const remove = async () => {
    if (removing || removeUserId === "") return;

    setRemoving(true);
    setRemoveError(null);

    try {
      await removeCompanyMember(companyId, Number.parseInt(removeUserId, 10));
      toast.success("User removed from the company.");
      setConfirmOpen(false);
      setRemoveUserId("");
    } catch (error) {
      setRemoveError(
        error instanceof ApiError
          ? error.message
          : "We could not remove the user. Please try again.",
      );
      setConfirmOpen(false);
    } finally {
      setRemoving(false);
    }
  };

  return (
    <div className="flex flex-col gap-6">
      <div
        role="note"
        className="rounded-md border border-info-border bg-info-soft px-3 py-2 text-sm text-info-soft-foreground"
      >
        The API does not provide a company member list, so no roster is shown
        here. You can add or remove a specific member below; the server verifies
        each change against the company&#39;s real membership.
      </div>

      {!canViewUsers ? (
        <EmptyState
          title="User list unavailable"
          description="Adding or removing members needs access to the users list, which your role does not include."
        />
      ) : users.status === "error" ? (
        <ErrorState title="Could not load users" message={users.message} />
      ) : (
        <div className="grid gap-6 lg:grid-cols-2">
          <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4 sm:p-6">
            <div className="flex flex-col gap-1">
              <h2 className="text-sm font-semibold text-foreground">Add a member</h2>
              <p className="text-sm text-muted-foreground">
                Grant an existing user access to {activeCompany.name}.
              </p>
            </div>

            {addError ? <ErrorState title="Could not add member" message={addError} /> : null}

            <Field>
              <Label htmlFor="add-member-user">User</Label>
              <select
                id="add-member-user"
                className={selectClasses}
                value={addUserId}
                onChange={(event) => setAddUserId(event.target.value)}
                disabled={!usersReady || adding}
              >
                <option value="">Select a user…</option>
                {userOptions.map((user) => (
                  <option key={user.id} value={user.id}>
                    {labelFor(user)}
                  </option>
                ))}
              </select>
            </Field>

            <div>
              <Button onClick={add} loading={adding} disabled={!usersReady || addUserId === ""}>
                {adding ? "Adding…" : "Add member"}
              </Button>
            </div>
          </section>

          <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4 sm:p-6">
            <div className="flex flex-col gap-1">
              <h2 className="text-sm font-semibold text-foreground">Remove a member</h2>
              <p className="text-sm text-muted-foreground">
                Remove a user&#39;s access to {activeCompany.name}.
              </p>
            </div>

            {removeError ? (
              <ErrorState title="Could not remove member" message={removeError} />
            ) : null}

            <Field>
              <Label htmlFor="remove-member-user">User</Label>
              <select
                id="remove-member-user"
                className={selectClasses}
                value={removeUserId}
                onChange={(event) => setRemoveUserId(event.target.value)}
                disabled={!usersReady || removing}
              >
                <option value="">Select a user…</option>
                {userOptions.map((user) => (
                  <option key={user.id} value={user.id}>
                    {labelFor(user)}
                  </option>
                ))}
              </select>
            </Field>

            <div>
              <Button
                variant="danger"
                onClick={() => setConfirmOpen(true)}
                disabled={!usersReady || removeUserId === ""}
              >
                Remove member
              </Button>
            </div>
          </section>
        </div>
      )}

      <Dialog
        open={confirmOpen}
        title="Remove member"
        description={`Remove this user from ${activeCompany.name}?`}
        onClose={removing ? () => {} : () => setConfirmOpen(false)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirmOpen(false)} disabled={removing}>
              Cancel
            </Button>
            <Button variant="danger" onClick={remove} loading={removing}>
              Remove
            </Button>
          </>
        }
      >
        <p className="text-sm text-muted-foreground">
          {selectedForRemoval
            ? `${selectedForRemoval.full_name} (${selectedForRemoval.email}) will lose access to ${activeCompany.name}. Their user account is not deleted.`
            : "The selected user will lose access to this company. Their user account is not deleted."}
        </p>
      </Dialog>
    </div>
  );
}
