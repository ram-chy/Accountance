"use client";

import Link from "next/link";
import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import type { Company, CompanySettings } from "@/lib/company/types";
import { useCompanySettings } from "@/lib/company/use-company-settings";

import { CompanyLifecycleDialog } from "./company-lifecycle-dialog";
import { CompanyProfileForm } from "./company-profile-form";
import { CompanySettingsForm } from "./company-settings-form";
import { CompanySettingsSkeleton } from "./company-settings-skeleton";

/**
 * Company administration for the active company.
 *
 * Three capabilities shape the screen, each enforced by the backend and
 * reflected here:
 *   - `companies.update`            edit the profile (Admin, Manager)
 *   - `companies.settings.view/update`  read/edit system settings (Admin, Manager)
 *   - `companies.delete`            deactivate/reactivate (Admin)
 *
 * The profile is read from the already-loaded session snapshot; the settings are
 * a separate company-scoped read so they are always served by the endpoint that
 * owns them. The base (functional) currency is intentionally absent — the
 * backend exposes no way to read its current value — see the Phase 20 report.
 */
export function CompanyView() {
  const { activeCompany, refresh } = useAuth();
  const { can } = usePermissions();

  const [reloadToken, setReloadToken] = useState(0);
  const [lifecycleOpen, setLifecycleOpen] = useState(false);

  const canUpdateProfile = can("companies.update");
  const canViewSettings = can("companies.settings.view");
  const canUpdateSettings = can("companies.settings.update");
  const canDelete = can("companies.delete");
  const canViewCurrencies = can("accounting.currency.view");

  const companyId = activeCompany?.id ?? null;

  const settingsState = useCompanySettings({
    companyId,
    enabled: canViewSettings,
    reloadToken,
  });

  const reloadSettings = () => setReloadToken((token) => token + 1);

  if (!activeCompany) {
    return (
      <EmptyState
        title="No company selected"
        description="Choose a company to view and manage its settings."
      />
    );
  }

  const company = activeCompany;

  return (
    <div className="flex flex-col gap-6">
      <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4 sm:p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-sm font-semibold text-foreground">Company profile</h2>
          <Badge variant={company.is_active ? "success" : "neutral"}>
            {company.is_active ? "Active" : "Inactive"}
          </Badge>
        </div>

        {canUpdateProfile ? (
          <CompanyProfileForm
            key={company.updated_at ?? company.id}
            company={company}
            onSaved={() => {
              void refresh();
            }}
          />
        ) : (
          <ReadOnlyProfile company={company} />
        )}
      </section>

      {canViewSettings ? (
        <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4 sm:p-6">
          <div className="flex flex-col gap-1">
            <h2 className="text-sm font-semibold text-foreground">
              Numbering, terms and defaults
            </h2>
            <p className="text-sm text-muted-foreground">
              Company-wide defaults applied to new documents.
            </p>
          </div>

          {settingsState.status === "loading" ? <CompanySettingsSkeleton /> : null}

          {settingsState.status === "unavailable" ? (
            <EmptyState title="Settings unavailable" description={settingsState.message} />
          ) : null}

          {settingsState.status === "error" ? (
            <ErrorState
              title="Could not load company settings"
              message={settingsState.message}
              requestId={settingsState.requestId}
              action={
                <Button variant="secondary" onClick={reloadSettings}>
                  Try again
                </Button>
              }
            />
          ) : null}

          {settingsState.status === "ready" && settingsState.settings !== null ? (
            canUpdateSettings ? (
              <CompanySettingsForm
                key={settingsState.settings.updated_at ?? "settings"}
                companyId={company.id}
                settings={settingsState.settings}
                canViewCurrencies={canViewCurrencies}
                onSaved={reloadSettings}
              />
            ) : (
              <ReadOnlySettings settings={settingsState.settings} />
            )
          ) : null}
        </section>
      ) : null}

      {canDelete ? (
        <section className="flex flex-col gap-4 rounded-lg border border-danger-border bg-surface p-4 sm:p-6">
          <div className="flex flex-col gap-1">
            <h2 className="text-sm font-semibold text-foreground">
              Company lifecycle
            </h2>
            <p className="text-sm text-muted-foreground">
              Deactivating takes this company out of use without deleting its
              records. It can be reactivated later.
            </p>
          </div>

          <div>
            {company.is_active ? (
              <Button variant="danger" onClick={() => setLifecycleOpen(true)}>
                Deactivate company
              </Button>
            ) : (
              <Button variant="primary" onClick={() => setLifecycleOpen(true)}>
                Reactivate company
              </Button>
            )}
          </div>
        </section>
      ) : null}

      {canDelete ? (
        <CompanyLifecycleDialog
          open={lifecycleOpen}
          company={company}
          action={company.is_active ? "deactivate" : "activate"}
          onClose={() => setLifecycleOpen(false)}
          onDone={() => {
            setLifecycleOpen(false);
            void refresh();
          }}
        />
      ) : null}

      <p className="text-xs text-muted-foreground">
        Managing people? See{" "}
        <Link
          href="/settings/company/members"
          className="font-medium text-primary-strong underline underline-offset-2"
        >
          Members
        </Link>{" "}
        and{" "}
        <Link
          href="/settings/users"
          className="font-medium text-primary-strong underline underline-offset-2"
        >
          Users
        </Link>
        .
      </p>
    </div>
  );
}

function ReadOnlyProfile({ company }: { company: Company }) {
  return (
    <div className="flex flex-col gap-4">
      <dl className="grid gap-4 sm:grid-cols-2">
        <Detail label="Name" value={company.name} />
        <Detail label="Legal name" value={company.legal_name ?? "—"} />
        <Detail label="Registration number" value={company.registration_number ?? "—"} />
        <Detail label="Tax number" value={company.tax_number ?? "—"} />
        <Detail label="Email" value={company.email ?? "—"} />
        <Detail label="Phone" value={company.phone ?? "—"} />
        <Detail label="Website" value={company.website ?? "—"} />
        <Detail
          label="Country"
          value={company.address.country_code ?? "—"}
        />
        <Detail label="Timezone" value={company.timezone} />
        <Detail label="Date format" value={company.date_format} />
      </dl>
      <p className="text-xs text-muted-foreground">
        Editing the company profile requires the company update permission.
      </p>
    </div>
  );
}

function ReadOnlySettings({ settings }: { settings: CompanySettings }) {
  return (
    <dl className="grid gap-4 sm:grid-cols-2">
      <Detail label="Invoice number prefix" value={settings.invoice_number_prefix ?? "—"} />
      <Detail label="Quotation number prefix" value={settings.quotation_number_prefix ?? "—"} />
      <Detail
        label="Default payment terms (days)"
        value={
          settings.default_payment_terms_days === null
            ? "—"
            : String(settings.default_payment_terms_days)
        }
      />
      <Detail
        label="Default document currency"
        value={
          settings.default_currency_id === null ? "—" : `#${settings.default_currency_id}`
        }
      />
      <Detail
        label="Fiscal year starts in"
        value={
          settings.default_fiscal_year_start_month === null
            ? "—"
            : monthName(settings.default_fiscal_year_start_month)
        }
      />
    </dl>
  );
}

function monthName(month: number): string {
  const names = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December",
  ];
  return names[month - 1] ?? String(month);
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
        {label}
      </dt>
      <dd className="text-sm break-words text-foreground">{value}</dd>
    </div>
  );
}
