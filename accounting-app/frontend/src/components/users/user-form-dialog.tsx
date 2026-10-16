"use client";

import { useState, type FormEvent } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input, Label } from "@/components/ui/field";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { PASSWORD_POLICY_HINT } from "@/lib/auth/password";
import { createUser, updateUser } from "@/lib/users/api";
import type { User, UserCreatePayload, UserUpdatePayload } from "@/lib/users/types";
import { FIXED_ROLES } from "@/lib/users/types";
import { parseUserOrThrow, validateUserCreate, validateUserUpdate } from "@/lib/users/validators";

/**
 * Create/edit a user account.
 *
 * Mirrors `StoreUserRequest` (`POST /users`) and `UpdateUserRequest`
 * (`PUT /users/{id}`). Roles are chosen from the fixed `RoleName` set — there is
 * no roles endpoint, so the available roles are the application-defined four.
 *
 * When editing the signed-in user, roles and account status are locked: the
 * backend refuses to let a caller change their own roles or deactivate
 * themselves, so offering the controls would only manufacture a `403`. The
 * password is optional on edit (`sometimes|nullable`) and omitted when blank.
 */
export function UserFormDialog({
  open,
  user,
  selfId,
  onClose,
  onSaved,
}: {
  open: boolean;
  /** null = create, otherwise edit. */
  user: User | null;
  selfId: number | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const isEdit = user !== null;
  const isSelf = user !== null && selfId !== null && user.id === selfId;

  const [firstName, setFirstName] = useState(user?.first_name ?? "");
  const [lastName, setLastName] = useState(user?.last_name ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [mobile, setMobile] = useState(user?.mobile_no ?? "");
  const [password, setPassword] = useState("");
  const [roles, setRoles] = useState<string[]>(user?.roles.map((role) => role.name) ?? []);
  const [isActive, setIsActive] = useState(user?.is_active ?? true);

  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const toggleRole = (role: string) => {
    setRoles((current) =>
      current.includes(role)
        ? current.filter((entry) => entry !== role)
        : [...current, role],
    );
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (submitting) return;

    const mobileValue = mobile.trim() === "" ? null : mobile.trim();

    if (!isEdit) {
      const payload: UserCreatePayload = {
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        email: email.trim(),
        mobile_no: mobileValue,
        password,
        roles,
      };

      const clientErrors = validateUserCreate(payload);
      if (Object.keys(clientErrors).length > 0) {
        setErrors(clientErrors);
        setFormError("Please correct the highlighted fields.");
        return;
      }

      setSubmitting(true);
      setErrors({});
      setFormError(null);

      try {
        parseUserOrThrow(await createUser(payload), "user");
        toast.success("User created.");
        onSaved();
      } catch (error) {
        handleFailure(error);
      } finally {
        setSubmitting(false);
      }

      return;
    }

    // Edit. Roles and status are only included when the caller may change them.
    const payload: UserUpdatePayload = {
      first_name: firstName.trim(),
      last_name: lastName.trim(),
      email: email.trim(),
      mobile_no: mobileValue,
    };

    if (!isSelf) {
      payload.roles = roles;
      payload.is_active = isActive;
    }

    if (password !== "") {
      payload.password = password;
    }

    const clientErrors = validateUserUpdate(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setErrors({});
    setFormError(null);

    try {
      parseUserOrThrow(await updateUser(user.id, payload), "user");
      toast.success("User updated.");
      onSaved();
    } catch (error) {
      handleFailure(error);
    } finally {
      setSubmitting(false);
    }
  };

  const handleFailure = (error: unknown) => {
    if (error instanceof ApiError) {
      setErrors(firstFieldErrors(error.errors));
      setFormError(error.message);
    } else {
      setFormError(
        `We could not ${isEdit ? "update" : "create"} the user. Please try again.`,
      );
    }
  };

  return (
    <Dialog
      open={open}
      title={isEdit ? "Edit user" : "New user"}
      description={
        isEdit
          ? `Update ${user.full_name}'s account.`
          : "Create a new user account and assign at least one role."
      }
      onClose={submitting ? () => {} : onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="user-form" loading={submitting}>
            {isEdit ? "Save changes" : "Create user"}
          </Button>
        </>
      }
    >
      <form id="user-form" onSubmit={submit} noValidate className="flex flex-col gap-4">
        {formError ? (
          <ErrorState
            title={isEdit ? "Could not update user" : "Could not create user"}
            message={formError}
          />
        ) : null}

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="First name" htmlFor="user-first-name" required error={errors.first_name}>
            <Input
              id="user-first-name"
              value={firstName}
              onChange={(event) => setFirstName(event.target.value)}
              invalid={Boolean(errors.first_name)}
              maxLength={100}
              required
            />
          </FormField>

          <FormField label="Last name" htmlFor="user-last-name" required error={errors.last_name}>
            <Input
              id="user-last-name"
              value={lastName}
              onChange={(event) => setLastName(event.target.value)}
              invalid={Boolean(errors.last_name)}
              maxLength={100}
              required
            />
          </FormField>

          <FormField label="Email" htmlFor="user-email" required error={errors.email}>
            <Input
              id="user-email"
              type="email"
              inputMode="email"
              autoComplete="off"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              invalid={Boolean(errors.email)}
              maxLength={255}
              required
            />
          </FormField>

          <FormField
            label="Mobile"
            htmlFor="user-mobile"
            error={errors.mobile_no}
            hint="7 to 20 digits, optionally starting with +."
          >
            <Input
              id="user-mobile"
              value={mobile}
              onChange={(event) => setMobile(event.target.value)}
              invalid={Boolean(errors.mobile_no)}
              maxLength={20}
            />
          </FormField>
        </div>

        <FormField
          label={isEdit ? "New password" : "Password"}
          htmlFor="user-password"
          required={!isEdit}
          hint={isEdit ? `${PASSWORD_POLICY_HINT} Leave blank to keep the current password.` : PASSWORD_POLICY_HINT}
          error={errors.password}
        >
          <Input
            id="user-password"
            type="password"
            autoComplete="new-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            invalid={Boolean(errors.password)}
            required={!isEdit}
          />
        </FormField>

        <fieldset className="flex flex-col gap-2">
          <legend className="text-sm font-medium text-foreground">
            Roles<span aria-hidden="true" className="ml-0.5 text-danger">*</span>
          </legend>
          {isSelf ? (
            <p className="text-xs text-muted-foreground">
              You cannot change your own roles.
            </p>
          ) : null}
          <div className="flex flex-wrap gap-3">
            {FIXED_ROLES.map((role) => (
              <label key={role} className="flex items-center gap-2 text-sm text-foreground">
                <input
                  type="checkbox"
                  name="roles"
                  value={role}
                  checked={roles.includes(role)}
                  onChange={() => toggleRole(role)}
                  disabled={isSelf || submitting}
                />
                {role}
              </label>
            ))}
          </div>
          {errors.roles ? (
            <p role="alert" className="text-xs font-medium text-danger">
              {errors.roles}
            </p>
          ) : null}
        </fieldset>

        {isEdit ? (
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="user-active">Account status</Label>
            <label className="flex items-center gap-2 text-sm text-foreground">
              <input
                id="user-active"
                type="checkbox"
                checked={isActive}
                onChange={(event) => setIsActive(event.target.checked)}
                disabled={isSelf || submitting}
              />
              Active
            </label>
            {isSelf ? (
              <p className="text-xs text-muted-foreground">
                You cannot deactivate your own account.
              </p>
            ) : null}
          </div>
        ) : null}
      </form>
    </Dialog>
  );
}
