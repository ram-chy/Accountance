"use client";

import { useMemo, useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { ErrorState } from "@/components/ui/error-state";
import { ApiError } from "@/lib/api/errors";

import { updateTaxAccountMapping } from "@/lib/taxes/api";
import type { TaxAccountMapping, TaxAccountRef } from "@/lib/taxes/types";
import {
  useTaxAccountOptions,
  type TaxAccountOption,
} from "@/lib/taxes/use-tax-account-options";

/**
 * Tax account mapping panel.
 *
 * Output tax is a liability (money owed to the authority); input tax is an asset
 * (money to reclaim). The two pickers are therefore filtered to those types, and
 * each can be cleared. The write is a full replacement: both fields are always
 * submitted, so clearing one side submits `null` rather than leaving the previous
 * value in place.
 *
 * Loading the accounts themselves is gated on `accounts.view` so a user without
 * that permission never triggers a protected account read; they still see the
 * accounts already recorded on the mapping, which came with the tax payload.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type AccountSelectProps = {
  id: string;
  label: string;
  hint: string;
  accountType: string;
  value: number | null;
  current: TaxAccountRef | null;
  options: TaxAccountOption[];
  loading: boolean;
  disabled: boolean;
  onChange: (value: number | null) => void;
};

function AccountSelect({
  id,
  label,
  hint,
  accountType,
  value,
  current,
  options,
  loading,
  disabled,
  onChange,
}: AccountSelectProps) {
  const eligible = options.filter((option) => option.account_type === accountType);
  const currentMissing =
    value !== null && !eligible.some((option) => option.id === value) && current !== null;

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-sm font-medium text-foreground">
        {label}
      </label>
      <select
        id={id}
        className={selectClasses}
        value={value === null ? "" : String(value)}
        disabled={disabled || loading}
        onChange={(event) => onChange(event.target.value === "" ? null : Number(event.target.value))}
      >
        <option value="">Not set</option>
        {currentMissing && current ? (
          <option value={String(current.id)}>
            {current.code} · {current.name} (current{current.is_active ? "" : ", inactive"})
          </option>
        ) : null}
        {eligible.map((option) => (
          <option key={option.id} value={String(option.id)}>
            {option.code} · {option.name}
            {option.is_active ? "" : " (inactive)"}
          </option>
        ))}
      </select>
      <p className="text-xs text-muted-foreground">{hint}</p>
    </div>
  );
}

function ReadOnlyAccount({ label, account }: { label: string; account: TaxAccountRef | null }) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-sm font-medium text-foreground">{label}</span>
      <span className="text-sm text-muted-foreground">
        {account === null ? "Not set" : `${account.code} · ${account.name}`}
      </span>
    </div>
  );
}

export type TaxMappingPanelProps = {
  companyId: number | null;
  taxId: number;
  mapping: TaxAccountMapping | null;
  canUpdate: boolean;
  canViewAccounts: boolean;
  onSaved: () => Promise<void> | void;
};

export function TaxMappingPanel({
  companyId,
  taxId,
  mapping,
  canUpdate,
  canViewAccounts,
  onSaved,
}: TaxMappingPanelProps) {
  const persistedOutput = mapping?.output_account_id ?? null;
  const persistedInput = mapping?.input_account_id ?? null;

  const [outputId, setOutputId] = useState<number | null>(persistedOutput);
  const [inputId, setInputId] = useState<number | null>(persistedInput);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const options = useTaxAccountOptions(companyId, canViewAccounts);

  const dirty = useMemo(
    () => outputId !== persistedOutput || inputId !== persistedInput,
    [outputId, persistedOutput, inputId, persistedInput],
  );

  const editable = canUpdate && canViewAccounts;

  const reset = () => {
    setOutputId(persistedOutput);
    setInputId(persistedInput);
    setError(null);
  };

  const save = async () => {
    setPending(true);
    setError(null);

    try {
      await updateTaxAccountMapping(taxId, {
        output_account_id: outputId,
        input_account_id: inputId,
      });
      toast.success("Tax account mapping saved.");
      await onSaved();
    } catch (caught) {
      setError(
        caught instanceof ApiError
          ? caught.message
          : "Something went wrong. Please try again.",
      );
    } finally {
      setPending(false);
    }
  };

  return (
    <section className="rounded-lg border border-border bg-surface p-4 sm:p-6">
      <div className="flex flex-col gap-1">
        <h2 className="text-sm font-semibold text-foreground">Account mapping</h2>
        <p className="text-sm text-muted-foreground">
          Where this tax posts. Output tax is a liability account; input tax is an asset account.
          Leave a side unset until you are ready to post documents that use it.
        </p>
      </div>

      <div className="mt-4 flex flex-col gap-3">
        {error ? <ErrorState title="Could not save the mapping" message={error} /> : null}

        {editable ? (
          <>
            {options.status === "error" ? (
              <ErrorState title="Could not load accounts" message={options.message ?? ""} />
            ) : null}

            <div className="grid gap-4 sm:grid-cols-2">
              <AccountSelect
                id="tax-output-account"
                label="Output tax account"
                hint="Charged on sales. Must be a liability account."
                accountType="LIABILITY"
                value={outputId}
                current={mapping?.output_account ?? null}
                options={options.items}
                loading={options.status === "loading"}
                disabled={pending}
                onChange={setOutputId}
              />
              <AccountSelect
                id="tax-input-account"
                label="Input tax account"
                hint="Recovered on purchases. Must be an asset account."
                accountType="ASSET"
                value={inputId}
                current={mapping?.input_account ?? null}
                options={options.items}
                loading={options.status === "loading"}
                disabled={pending}
                onChange={setInputId}
              />
            </div>

            <div className="flex flex-wrap items-center justify-end gap-2">
              <Button variant="secondary" disabled={!dirty || pending} onClick={reset}>
                Reset
              </Button>
              <Button loading={pending} disabled={!dirty} onClick={() => void save()}>
                Save mapping
              </Button>
            </div>
          </>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            <ReadOnlyAccount label="Output tax account" account={mapping?.output_account ?? null} />
            <ReadOnlyAccount label="Input tax account" account={mapping?.input_account ?? null} />
            <p className="text-xs text-muted-foreground sm:col-span-2">
              {canUpdate
                ? "You need access to the chart of accounts to change this mapping."
                : "Your role cannot change the tax account mapping."}
            </p>
          </div>
        )}
      </div>
    </section>
  );
}
