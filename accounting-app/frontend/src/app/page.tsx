import { ThemeToggle } from "@/components/theme/theme-toggle";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { FormField, Input } from "@/components/ui/field";
import { API_BASE_URL, isApiConfigured } from "@/lib/api/config";
import { formatDateOnly, formatTimestamp } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";

const FOUNDATION_SAMPLES = [
  "1234.5600",
  "0.5000",
  "-125.0000",
  "1000000.0000",
] as const;

const FOUNDATION_DATES = ["2026-01-15", "2026-02-31"] as const;

const FOUNDATION_AREAS = [
  {
    title: "Design tokens",
    description:
      "Semantic colours, spacing, and typography in globals.css, mapped to Tailwind utilities.",
  },
  {
    title: "Theming",
    description:
      "Light and dark palettes with a pre-paint class switch and no flash.",
  },
  {
    title: "API client",
    description:
      "Envelope-aware fetch wrapper with a Phase 2 header hook and normalised errors.",
  },
  {
    title: "Formatting",
    description: "Precision-safe decimal and date helpers for backend shapes.",
  },
] as const;

export default function HomePage() {
  return (
    <div className="mx-auto flex min-h-dvh w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
      <header className="flex items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <span
            aria-hidden="true"
            className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-base font-semibold text-on-primary"
          >
            A
          </span>
          <div>
            <p className="text-base font-semibold text-foreground">Accountance</p>
            <p className="text-sm text-muted-foreground">Accounting web app</p>
          </div>
        </div>
        <ThemeToggle />
      </header>

      <main className="flex flex-col gap-8">
        <section>
          <Card>
            <CardHeader>
              <div className="flex flex-wrap items-center gap-2">
                <CardTitle>Frontend foundation</CardTitle>
                <Badge variant="success">Phase 1</Badge>
              </div>
              <CardDescription>
                The Next.js foundation is running: design tokens, theming, UI
                primitives, and the API/formatting utilities that the
                authentication and accounting screens will build on.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="flex flex-wrap items-center gap-2 text-sm">
                <span className="text-muted-foreground">API base URL:</span>
                {isApiConfigured ? (
                  <>
                    <Badge variant="info">Configured</Badge>
                    <code className="rounded bg-surface-muted px-2 py-0.5 font-mono text-xs text-foreground">
                      {API_BASE_URL}
                    </code>
                  </>
                ) : (
                  <Badge variant="warning">Not configured</Badge>
                )}
              </div>
              {!isApiConfigured ? (
                <p className="mt-2 text-sm text-muted-foreground">
                  Set <code className="font-mono">NEXT_PUBLIC_API_BASE_URL</code>{" "}
                  in <code className="font-mono">.env.local</code> and restart the
                  dev server to enable API calls.
                </p>
              ) : null}
            </CardContent>
          </Card>
        </section>

        <section aria-labelledby="foundation-areas">
          <h2
            id="foundation-areas"
            className="mb-3 text-sm font-medium text-muted-foreground"
          >
            Foundation areas
          </h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {FOUNDATION_AREAS.map((area) => (
              <Card key={area.title}>
                <CardHeader>
                  <CardTitle className="text-sm">{area.title}</CardTitle>
                </CardHeader>
                <CardContent className="pt-0">
                  <p className="text-sm text-muted-foreground">
                    {area.description}
                  </p>
                </CardContent>
              </Card>
            ))}
          </div>
        </section>

        <div className="grid gap-4 lg:grid-cols-2">
          <section aria-labelledby="ui-primitives">
            <Card className="h-full">
              <CardHeader>
                <CardTitle id="ui-primitives">UI primitives</CardTitle>
                <CardDescription>
                  Buttons, badges, and form controls with light/dark and focus
                  states.
                </CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col gap-5">
                <div className="flex flex-wrap items-center gap-2">
                  <Button>Primary</Button>
                  <Button variant="secondary">Secondary</Button>
                  <Button variant="outline">Outline</Button>
                  <Button variant="ghost">Ghost</Button>
                  <Button variant="danger">Danger</Button>
                  <Button loading>Loading</Button>
                  <Button disabled>Disabled</Button>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  <Badge>Neutral</Badge>
                  <Badge variant="primary">Primary</Badge>
                  <Badge variant="success">Success</Badge>
                  <Badge variant="warning">Warning</Badge>
                  <Badge variant="danger">Danger</Badge>
                  <Badge variant="info">Info</Badge>
                </div>

                <FormField
                  label="Company name"
                  htmlFor="demo-company"
                  hint="Shown on reports and documents."
                >
                  <Input
                    id="demo-company"
                    placeholder="Example Company Sdn Bhd"
                    aria-describedby="demo-company-hint"
                  />
                </FormField>

                <FormField
                  label="Account code"
                  htmlFor="demo-code"
                  error="This field is required."
                >
                  <Input
                    id="demo-code"
                    invalid
                    defaultValue=""
                    placeholder="e.g. 1000"
                    aria-describedby="demo-code-error"
                  />
                </FormField>
              </CardContent>
            </Card>
          </section>

          <section aria-labelledby="formatting-preview">
            <Card className="h-full">
              <CardHeader>
                <CardTitle id="formatting-preview">
                  Formatting preview
                </CardTitle>
                <CardDescription>
                  Illustrative sample values only — not real account data.
                </CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col gap-5">
                <div>
                  <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Decimal strings (2 dp)
                  </p>
                  <dl className="divide-y divide-border rounded-md border border-border">
                    {FOUNDATION_SAMPLES.map((sample) => (
                      <div
                        key={sample}
                        className="flex items-center justify-between gap-4 px-3 py-2"
                      >
                        <dt className="font-mono text-xs text-muted-foreground">
                          {sample}
                        </dt>
                        <dd className="font-mono text-sm text-foreground">
                          {formatMoney(sample, { decimals: 2 })}
                        </dd>
                      </div>
                    ))}
                  </dl>
                </div>

                <div>
                  <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Dates
                  </p>
                  <dl className="divide-y divide-border rounded-md border border-border">
                    {FOUNDATION_DATES.map((sample) => (
                      <div
                        key={sample}
                        className="flex items-center justify-between gap-4 px-3 py-2"
                      >
                        <dt className="font-mono text-xs text-muted-foreground">
                          {sample}
                        </dt>
                        <dd className="text-sm text-foreground">
                          {formatDateOnly(sample)}
                        </dd>
                      </div>
                    ))}
                    <div className="flex items-center justify-between gap-4 px-3 py-2">
                      <dt className="font-mono text-xs text-muted-foreground">
                        2026-01-15T09:30:00Z
                      </dt>
                      <dd className="text-sm text-foreground">
                        {formatTimestamp("2026-01-15T09:30:00Z")}
                      </dd>
                    </div>
                  </dl>
                </div>
              </CardContent>
            </Card>
          </section>
        </div>
      </main>

      <footer className="mt-auto border-t border-border pt-4 text-xs text-muted-foreground">
        Foundation preview for Phase 1. Authentication, company context, and the
        accounting screens are added in later phases.
      </footer>
    </div>
  );
}
