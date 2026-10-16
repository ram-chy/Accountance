"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { toast } from "sonner";

import { useAuth } from "@/components/auth/auth-provider";
import { ThemeToggle } from "@/components/theme/theme-toggle";
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
import { ApiError } from "@/lib/api/errors";

type LoginFormProps = {
  nextPath: string;
};

/**
 * Credential form.
 *
 * Delegates to `AuthProvider.login`, which talks to the BFF and stores the
 * session in the httpOnly cookie. Credentials are never persisted client-side,
 * and failures are shown without disclosing whether the email exists.
 */
export function LoginForm({ nextPath }: LoginFormProps) {
  const router = useRouter();
  const { login } = useAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setSubmitting(true);
    setFormError(null);
    setFieldErrors({});

    try {
      await login(email, password);
      toast.success("Signed in.");
      router.replace(nextPath);
    } catch (error) {
      if (error instanceof ApiError) {
        if (error.isValidation && error.errors) {
          setFieldErrors(error.errors);
          setFormError("Please correct the highlighted fields.");
        } else if (error.isUnauthorized) {
          setFormError("Those credentials do not match our records.");
        } else {
          setFormError(error.message);
        }
      } else {
        setFormError("Something went wrong. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="flex min-h-dvh flex-col">
      <div className="flex justify-end p-4">
        <ThemeToggle />
      </div>

      <div className="flex flex-1 items-center justify-center px-4 pb-16">
        <div className="w-full max-w-sm">
          <div className="mb-6 flex flex-col items-center gap-2 text-center">
            <span
              aria-hidden="true"
              className="flex h-11 w-11 items-center justify-center rounded-lg bg-primary text-lg font-semibold text-on-primary"
            >
              A
            </span>
            <h1 className="text-lg font-semibold text-foreground">
              Sign in to Accountance
            </h1>
            <p className="text-sm text-muted-foreground">
              Enter your credentials to continue.
            </p>
          </div>

          <Card>
            <CardHeader>
              <CardTitle>Welcome back</CardTitle>
              <CardDescription>
                Use the email address associated with your account.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form
                onSubmit={handleSubmit}
                noValidate
                className="flex flex-col gap-4"
              >
                {formError ? (
                  <ErrorState title="Sign-in failed" message={formError} />
                ) : null}

                <FormField
                  label="Email"
                  htmlFor="email"
                  required
                  error={fieldErrors.email?.[0]}
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
                    aria-describedby={
                      fieldErrors.email ? "email-error" : undefined
                    }
                  />
                </FormField>

                <FormField
                  label="Password"
                  htmlFor="password"
                  required
                  error={fieldErrors.password?.[0]}
                >
                  <Input
                    id="password"
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    required
                    invalid={Boolean(fieldErrors.password)}
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    aria-describedby={
                      fieldErrors.password ? "password-error" : undefined
                    }
                  />
                </FormField>

                <Button type="submit" loading={submitting} className="w-full">
                  {submitting ? "Signing in…" : "Sign in"}
                </Button>

                <div className="text-center">
                  <Link
                    href="/forgot-password"
                    className="text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                  >
                    Forgot your password?
                  </Link>
                </div>
              </form>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
