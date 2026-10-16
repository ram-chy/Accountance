"use client";

import { useState, type FormEvent } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { changePassword } from "@/lib/auth/client";
import {
  PASSWORD_POLICY_HINT,
  validatePasswordStrength,
} from "@/lib/auth/password";

/**
 * Change-password form.
 *
 * The BFF exchanges the current password for a replacement token and re-seals
 * the session cookie, so the user stays signed in and the token used for the
 * request can no longer be replayed. The new password is validated locally
 * against the same policy the backend enforces, then again by the backend.
 *
 * On success the fields are cleared; nothing about the password is retained in
 * component state beyond the submission.
 */
export function ChangePasswordForm() {
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (submitting) return;

    const clientErrors: Record<string, string> = {};

    if (current === "") {
      clientErrors.current_password = "Enter your current password.";
    }

    const strength = validatePasswordStrength(next);
    if (strength !== null) {
      clientErrors.new_password = strength;
    }

    if (next !== confirmation) {
      clientErrors.new_password_confirmation =
        "The password confirmation does not match.";
    }

    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setErrors({});
    setFormError(null);

    try {
      await changePassword({
        current_password: current,
        new_password: next,
        new_password_confirmation: confirmation,
      });
      toast.success("Password changed.");
      setCurrent("");
      setNext("");
      setConfirmation("");
    } catch (error) {
      if (error instanceof ApiError) {
        if (error.isValidation) {
          setErrors(firstFieldErrors(error.errors));
          setFormError("Please correct the highlighted fields.");
        } else if (error.isForbidden) {
          setErrors(firstFieldErrors(error.errors));
          setFormError(error.message);
        } else {
          setFormError(error.message);
        }
      } else {
        setFormError("We could not change your password. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {formError ? (
        <ErrorState title="Could not change password" message={formError} />
      ) : null}

      <FormField
        label="Current password"
        htmlFor="current_password"
        required
        error={errors.current_password}
      >
        <Input
          id="current_password"
          name="current_password"
          type="password"
          autoComplete="current-password"
          required
          invalid={Boolean(errors.current_password)}
          value={current}
          onChange={(event) => setCurrent(event.target.value)}
          aria-describedby={
            errors.current_password ? "current_password-error" : undefined
          }
        />
      </FormField>

      <FormField
        label="New password"
        htmlFor="new_password"
        required
        hint={PASSWORD_POLICY_HINT}
        error={errors.new_password}
      >
        <Input
          id="new_password"
          name="new_password"
          type="password"
          autoComplete="new-password"
          required
          invalid={Boolean(errors.new_password)}
          value={next}
          onChange={(event) => setNext(event.target.value)}
          aria-describedby={errors.new_password ? "new_password-error" : "new_password-hint"}
        />
      </FormField>

      <FormField
        label="Confirm new password"
        htmlFor="new_password_confirmation"
        required
        error={errors.new_password_confirmation}
      >
        <Input
          id="new_password_confirmation"
          name="new_password_confirmation"
          type="password"
          autoComplete="new-password"
          required
          invalid={Boolean(errors.new_password_confirmation)}
          value={confirmation}
          onChange={(event) => setConfirmation(event.target.value)}
          aria-describedby={
            errors.new_password_confirmation
              ? "new_password_confirmation-error"
              : undefined
          }
        />
      </FormField>

      <div>
        <Button type="submit" loading={submitting}>
          {submitting ? "Changing…" : "Change password"}
        </Button>
      </div>
    </form>
  );
}
