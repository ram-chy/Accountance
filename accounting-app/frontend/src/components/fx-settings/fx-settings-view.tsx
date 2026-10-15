"use client";

import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { useFxSetting } from "@/lib/fx-settings/use-fx-setting";
import { formatDateOnly } from "@/lib/format/date";

import { FxSettingsForm } from "./fx-settings-form";
import { FxSettingsSkeleton } from "./fx-settings-skeleton";

/**
 * Currency settings screen.
 *
 * The only company-level currency setting the backend exposes is the pair of
 * accounts realised foreign exchange gains and losses post to, so this screen
 * renders exactly that. It is a per-company singleton (`GET`/`PUT
 * /accounting/fx-settings`), addressed without an id.
 *
 * Both reading and writing the setting require `accounting.fx.update`, so the
 * whole screen is gated on that one capability. The backend re-authorises every
 * call.
 */
export function FxSettingsView() {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;

  const canManage = can("accounting.fx.update");

  const [reloadToken, setReloadToken] = useState(0);
  const state = useFxSetting({ companyId, enabled: canManage, reloadToken });

  const reload = () => setReloadToken((token) => token + 1);

  const header = (
    <div className="flex flex-col gap-1">
      <h1 className="text-xl font-semibold text-foreground">Currency settings</h1>
      <p className="text-sm text-muted-foreground">
        Where this company posts realised gains and losses when a foreign-currency amount is
        settled at a different rate than it was recorded.
      </p>
    </div>
  );

  const body = (() => {
    if (!activeCompany) {
      return (
        <EmptyState
          title="No company selected"
          description="Choose a company to view its currency settings."
        />
      );
    }

    if (!canManage) {
      return (
        <EmptyState
          title="Currency settings unavailable"
          description="Your role does not include access to FX settings."
        />
      );
    }

    if (state.status === "loading") {
      return <FxSettingsSkeleton />;
    }

    if (state.status === "unavailable") {
      return <EmptyState title="Currency settings unavailable" description={state.message} />;
    }

    if (state.status === "error") {
      return (
        <ErrorState
          title="Could not load currency settings"
          message={state.message}
          requestId={state.requestId}
          action={
            <Button variant="secondary" onClick={reload}>
              Try again
            </Button>
          }
        />
      );
    }

    if (state.setting === null) {
      return <FxSettingsSkeleton />;
    }

    const setting = state.setting;

    return (
      <section className="flex flex-col gap-6 rounded-lg border border-border bg-surface p-4 sm:p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <h2 className="text-sm font-semibold text-foreground">Realised FX accounts</h2>
            <Badge variant={setting.is_configured ? "success" : "warning"}>
              {setting.is_configured ? "Configured" : "Not configured"}
            </Badge>
          </div>
          {setting.updated_at ? (
            <p className="text-xs text-muted-foreground">
              Last updated {formatDateOnly(setting.updated_at.slice(0, 10))}
            </p>
          ) : null}
        </div>

        <p className="text-sm text-muted-foreground">
          Both accounts are set together or cleared together. The gain account must be a revenue
          account and the loss account an expense account.
        </p>

        <FxSettingsForm
          key={setting.updated_at ?? "fx-settings"}
          companyId={companyId}
          setting={setting}
          onSaved={reload}
        />
      </section>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      {header}
      {body}
    </div>
  );
}
