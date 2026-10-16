"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";

import { PublicShell } from "@/components/auth/public-shell";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { requestPasswordReset } from "@/lib/auth/client";

/**
 * Forgot-password form.
 *
 * Posts the address and always shows the same confirmation, whether or not the
 * account exists — the BFF and the backend both refuse to distinguish the two,
 * so the UI must not manufacture a signal either. A 422 is the only failure
 * surfaced as a field error; anything else is reported generically.
 */
export function ForgotPasswordForm() {
  const [email, setEmail] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (submitting) return;

    setSubmitting(true);
    setFormError(null);
    setFieldErrors({});

    try {
      await requestPasswordReset(email.trim());
      setSubmitted(true);
    } catch (error) {
      if (error instanceof ApiError) {
        if (error.isValidation) {
          setFieldErrors(firstFieldErrors(error.errors));
          setFormError("Please enter a valid email address.");
        } else {
          setFormError(
            "We could not send the reset instructions right now. Please try again.",
          );
        }
      } else {
        setFormError("Something went wrong. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <PublicShell
      title="Reset your password"
      subtitle="We will email you a link to choose a new password."
    >
      <Card>
        <CardHeader>
          <CardTitle>Forgot password</CardTitle>
          <CardDescription>
            Enter the email address associated with your account.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {submitted ? (
            <div className="flex flex-col gap-4">
              <div
                role="status"
                className="rounded-md border border-info-border bg-info-soft px-3 py-2 text-sm text-info-soft-foreground"
              >
                If an account exists for <strong>{email.trim()}</strong>, password
                recovery instructions have been sent.
              </div>
              <Link
                href="/login"
                className="text-sm font-medium text-primary-strong underline underline-offset-2"
              >
                Back to sign in
              </Link>
            </div>
          ) : (
            <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
              {formError ? (
                <ErrorState title="Could not send instructions" message={formError} />
              ) : null}

              <FormField
                label="Email"
                htmlFor="email"
                required
                error={fieldErrors.email}
              >
                <Input
                  id="email"
                  name="email"
                  type="email"
                  autoComplete="username"
                  inputMode="email"
                  required
                  invalid={Boolean(fieldErrors.email)}
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  aria-describedby={fieldErrors.email ? "email-error" : undefined}
                />
              </FormField>

              <Button type="submit" loading={submitting} className="w-full">
                {submitting ? "Sending…" : "Send reset link"}
              </Button>

              <Link
                href="/login"
                className="text-center text-sm font-medium text-primary-strong underline underline-offset-2"
              >
                Back to sign in
              </Link>
            </form>
          )}
        </CardContent>
      </Card>
    </PublicShell>
  );
}
