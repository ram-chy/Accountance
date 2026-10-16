"use client";

import Link from "next/link";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { resetPassword, validateResetToken } from "@/lib/auth/client";
import {
  PASSWORD_POLICY_HINT,
  validatePasswordStrength,
} from "@/lib/auth/password";

/**
 * Reset-password form.
 *
 * On mount the token is checked with the BFF (`GET /reset-password/{token}`)
 * before any password field is offered, so a stale link is caught immediately
 * rather than at submit. A `400` — the backend's single answer for an unknown
 * address and a used or expired token alike — becomes the "link not usable"
 * state; the two cases are never distinguished.
 *
 * After a successful reset no session is created: the user is directed to sign
 * in with the new password.
 */
export function ResetPasswordForm({ token, email }: { token: string; email: string }) {
  const [phase, setPhase] = useState<"checking" | "invalid" | "ready" | "done">(
    "checking",
  );
  const [checkFailed, setCheckFailed] = useState<string | null>(null);

  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const checkToken = useCallback(
    (signal: AbortSignal) => {
      validateResetToken(token, email, signal)
        .then(() => {
          if (signal.aborted) return;
          setPhase("ready");
        })
        .catch((error: unknown) => {
          if (signal.aborted) return;

          setPhase("invalid");

          if (!(error instanceof ApiError && error.status === 400)) {
            setCheckFailed(
              error instanceof ApiError
                ? error.message
                : "We could not verify the reset link right now.",
            );
          }
        });
    },
    [token, email],
  );

  useEffect(() => {
    const controller = new AbortController();

    checkToken(controller.signal);

    return () => controller.abort();
  }, [checkToken]);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (submitting) return;

    const clientErrors: Record<string, string> = {};

    const strength = validatePasswordStrength(password);
    if (strength !== null) {
      clientErrors.password = strength;
    }
    if (password !== confirmation) {
      clientErrors.password_confirmation = "The password confirmation does not match.";
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
      await resetPassword({
        token,
        email,
        password,
        password_confirmation: confirmation,
      });
      toast.success("Password reset. Sign in with your new password.");
      setPhase("done");
    } catch (error) {
      if (error instanceof ApiError) {
        if (error.status === 400) {
          // The token was consumed or expired between the check and submit.
          setPhase("invalid");
        } else if (error.isValidation) {
          setErrors(firstFieldErrors(error.errors));
          setFormError("Please correct the highlighted fields.");
        } else {
          setFormError(error.message);
        }
      } else {
        setFormError("We could not reset your password. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  if (phase === "checking") {
    return <LoadingState label="Checking your reset link…" />;
  }

  if (phase === "invalid") {
    return (
      <div className="flex flex-col gap-4">
        {checkFailed ? (
          <ErrorState title="Could not verify the link" message={checkFailed} />
        ) : null}
        <EmptyState
          title="This reset link is invalid or has expired"
          description="Reset links can only be used once. Request a new email and open the link from that message."
        />
        <Link
          href="/forgot-password"
          className="text-center text-sm font-medium text-primary-strong underline underline-offset-2"
        >
          Request a new link
        </Link>
      </div>
    );
  }

  if (phase === "done") {
    return (
      <div className="flex flex-col gap-4">
        <div
          role="status"
          className="rounded-md border border-success-border bg-success-soft px-3 py-2 text-sm text-success-soft-foreground"
        >
          Your password has been reset.
        </div>
        <Link
          href="/login"
          className="text-center text-sm font-medium text-primary-strong underline underline-offset-2"
        >
          Sign in
        </Link>
      </div>
    );
  }

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {formError ? (
        <ErrorState title="Could not reset password" message={formError} />
      ) : null}

      <p className="text-xs text-muted-foreground">
        Resetting the password for <strong>{email}</strong>.
      </p>

      <FormField
        label="New password"
        htmlFor="password"
        required
        hint={PASSWORD_POLICY_HINT}
        error={errors.password}
      >
        <Input
          id="password"
          name="password"
          type="password"
          autoComplete="new-password"
          required
          invalid={Boolean(errors.password)}
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          aria-describedby={errors.password ? "password-error" : "password-hint"}
        />
      </FormField>

      <FormField
        label="Confirm new password"
        htmlFor="password_confirmation"
        required
        error={errors.password_confirmation}
      >
        <Input
          id="password_confirmation"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          required
          invalid={Boolean(errors.password_confirmation)}
          value={confirmation}
          onChange={(event) => setConfirmation(event.target.value)}
          aria-describedby={
            errors.password_confirmation ? "password_confirmation-error" : undefined
          }
        />
      </FormField>

      <Button type="submit" loading={submitting} className="w-full">
        {submitting ? "Resetting…" : "Reset password"}
      </Button>
    </form>
  );
}
