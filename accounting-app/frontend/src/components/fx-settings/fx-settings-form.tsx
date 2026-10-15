"use client";

import { useState, type FormEvent } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { ErrorState } from "@/components/ui/error-state";
import { FormField } from "@/components/ui/field";
import { useAccountTypeOptions } from "@/lib/accounts/use-account-type-options";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { updateFxSetting } from "@/lib/fx-settings/api";
import type { CompanyFxSetting, FxSettingUpdatePayload } from "@/lib/fx-settings/types";
import {
  parseCompanyFxSettingOrThrow,
  validateFxSettingUpdate,
} from "@/lib/fx-settings/validators";

/**
 * The realised-FX gain/loss account pair form.
 *
 * Both fields are written together — the payload always carries both, and
 * clearing sends both as null — because the backend refuses a half-configured
 * state. The gain picker offers only revenue accounts and the loss picker only
 * expense accounts, mirroring the service's rule; the service remains the
 * authority and its message is shown when it refuses.
 *
 * An inactive account is still listed (with a suffix) when it is the currently
 * configured one, so the form always reflects the stored setting.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export type FxSettingsFormProps = {
  companyId: number | null;
  setting: CompanyFxSetting;
  onSaved: (setting: CompanyFxSetting) => void;
};

export function FxSettingsForm({ companyId, setting, onSaved }: FxSettingsFormProps) {
  const accountOptions = useAccountTypeOptions(companyId, true);
  const items = accountOptions.status === "ready" ? accountOptions.items : [];
  const gainOptions = items.filter((account) => account.account_type === "REVENUE");
  const lossOptions = items.filter((account) => account.account_type === "EXPENSE");
  const accountsReady = accountOptions.status === "ready";

  const [gainId, setGainId] = useState(
    setting.realized_gain_account_id === null ? "" : String(setting.realized_gain_account_id),
  );
  const [lossId, setLossId] = useState(
    setting.realized_loss_account_id === null ? "" : String(setting.realized_loss_account_id),
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const isConfigured = gainId !== "" && lossId !== "";

  const toId = (value: string): number | null => {
    if (value === "") return null;
    const parsed = Number.parseInt(value, 10);
    return Number.isInteger(parsed) ? parsed : null;
  };

  const run = async (payload: FxSettingUpdatePayload, cleared: boolean) => {
    if (submitting) return;

    const clientErrors = validateFxSettingUpdate(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);
    setErrors({});

    try {
      const saved = parseCompanyFxSettingOrThrow(await updateFxSetting(payload));
      toast.success(cleared ? "FX configuration cleared." : "FX configuration saved.");
      onSaved(saved);
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save the FX settings. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    void run(
      {
        realized_gain_account_id: toId(gainId),
        realized_loss_account_id: toId(lossId),
      },
      false,
    );
  };

  const clear = () => {
    setGainId("");
    setLossId("");
    void run(
      { realized_gain_account_id: null, realized_loss_account_id: null },
      true,
    );
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
      {formError ? (
        <p
          role="alert"
          className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
        >
          {formError}
        </p>
      ) : null}

      {accountOptions.status === "error" ? (
        <ErrorState
          title="Could not load accounts"
          message="The account pickers need the chart of accounts. Reload the page to try again."
        />
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField
          label="Realised gain account"
          htmlFor="fx-gain-account"
          error={errors.realized_gain_account_id}
          hint="A revenue account. Posted when a settled foreign-currency amount gains value."
        >
          <select
            id="fx-gain-account"
            className={selectClasses}
            value={gainId}
            onChange={(event) => setGainId(event.target.value)}
            disabled={!accountsReady || submitting}
          >
            <option value="">Not set</option>
            {gainOptions.map((account) => (
              <option key={account.id} value={account.id}>
                {account.code} — {account.name}
                {account.is_active ? "" : " (inactive)"}
              </option>
            ))}
          </select>
        </FormField>

        <FormField
          label="Realised loss account"
          htmlFor="fx-loss-account"
          error={errors.realized_loss_account_id}
          hint="An expense account. Posted when a settled foreign-currency amount loses value."
        >
          <select
            id="fx-loss-account"
            className={selectClasses}
            value={lossId}
            onChange={(event) => setLossId(event.target.value)}
            disabled={!accountsReady || submitting}
          >
            <option value="">Not set</option>
            {lossOptions.map((account) => (
              <option key={account.id} value={account.id}>
                {account.code} — {account.name}
                {account.is_active ? "" : " (inactive)"}
              </option>
            ))}
          </select>
        </FormField>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Button type="submit" loading={submitting} disabled={!accountsReady}>
          Save changes
        </Button>
        {isConfigured || setting.is_configured ? (
          <Button type="button" variant="ghost" onClick={clear} disabled={submitting}>
            Clear configuration
          </Button>
        ) : null}
      </div>
    </form>
  );
}
