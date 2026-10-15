"use client";

import { useMemo, useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FormField, Input } from "@/components/ui/field";
import { useAccountOptions } from "@/lib/accounts/use-account-options";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { formatDateOnly, todayDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import {
  capitalizeFixedAsset,
  deleteFixedAsset,
  depreciateFixedAsset,
  disposeFixedAsset,
} from "@/lib/fixed-assets/api";
import type {
  DepreciateResponse,
  DisposeFixedAssetPayload,
  DisposeResponse,
  FixedAsset,
} from "@/lib/fixed-assets/types";
import {
  parseDepreciateResponse,
  parseDisposeResponse,
  parseFixedAssetOrThrow,
  validateDispose,
} from "@/lib/fixed-assets/validators";

/**
 * Lifecycle action dialogs for a single fixed asset.
 *
 * Each action calls exactly one verified endpoint and surfaces the backend's own
 * validation errors verbatim. The dialogs deliberately do no accounting of their
 * own: capitalize has no body, depreciate only forwards an optional `as_of`, and
 * dispose sends the disposal date, optional proceeds, optional reason, and the
 * proceeds account. The server decides period, amount, posting date, and any
 * gain/loss, and the result is handed back so the detail view can refresh from
 * authoritative data.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

const textareaClasses =
  "min-h-16 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function money(value: string | null): string {
  return formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });
}

function ErrorBanner({ message }: { message: string | null }) {
  if (!message) return null;
  return (
    <p
      role="alert"
      className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
    >
      {message}
    </p>
  );
}

type CapitalizeDialogProps = {
  open: boolean;
  asset: FixedAsset;
  onClose: () => void;
  onDone: (asset: FixedAsset) => void;
};

/**
 * Capitalise a draft asset: posts the acquisition journal and moves the asset to
 * ACTIVE so depreciation can begin. No body is sent.
 */
export function CapitalizeDialog({ open, asset, onClose, onDone }: CapitalizeDialogProps) {
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    try {
      const raw = await capitalizeFixedAsset(asset.id);
      onDone(parseFixedAssetOrThrow(raw, "capitalised asset"));
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "We could not capitalise this asset.");
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title="Capitalise asset"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button onClick={submit} loading={submitting}>
            Capitalise
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3 text-sm text-muted-foreground">
        <ErrorBanner message={error} />
        <p>
          Capitalising <span className="font-medium text-foreground">{asset.asset_number}</span> posts the
          acquisition journal and starts depreciation from{" "}
          <span className="text-foreground">{formatDateOnly(asset.depreciation_start_date)}</span>.
        </p>
        <p>Once capitalised the asset can no longer be edited or deleted.</p>
      </div>
    </Dialog>
  );
}

type DepreciateDialogProps = {
  open: boolean;
  asset: FixedAsset;
  onClose: () => void;
  onDone: (result: DepreciateResponse) => void;
};

/**
 * Post the next depreciation charge. `as_of` is optional and only shifts the date
 * the "has the period ended" question is asked against; the server derives the
 * period, amount and posting date from the stored schedule.
 */
export function DepreciateDialog({ open, asset, onClose, onDone }: DepreciateDialogProps) {
  const [asOf, setAsOf] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    try {
      const raw = await depreciateFixedAsset(asset.id, asOf.trim() || undefined);
      const parsed = parseDepreciateResponse(raw);
      if (parsed === null) {
        setError("The server returned an unexpected depreciation response.");
        setSubmitting(false);
        return;
      }
      onDone(parsed);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "We could not post this depreciation.");
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title="Post depreciation"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="fa-depreciate-form" loading={submitting}>
            Post depreciation
          </Button>
        </>
      }
    >
      <form id="fa-depreciate-form" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <ErrorBanner message={error} />
        <p className="text-sm text-muted-foreground">
          Posts the next scheduled charge for{" "}
          <span className="font-medium text-foreground">{asset.asset_number}</span>. Monthly charge:{" "}
          {money(asset.monthly_charge)}. If no period has ended yet the server will refuse.
        </p>
        <FormField
          label="Ask as of date"
          htmlFor="fa-depreciate-asof"
          hint="Optional. Defaults to today."
        >
          <Input
            id="fa-depreciate-asof"
            type="date"
            value={asOf}
            onChange={(event) => setAsOf(event.target.value)}
          />
        </FormField>
      </form>
    </Dialog>
  );
}

type DisposeDialogProps = {
  open: boolean;
  asset: FixedAsset;
  companyId: number | null;
  onClose: () => void;
  onDone: (result: DisposeResponse) => void;
};

type DisposeForm = {
  disposal_date: string;
  proceeds: string;
  reason: string;
  proceeds_account_id: string;
};

/**
 * Dispose of an active or fully-depreciated asset. The server computes the
 * carrying value at disposal and any gain/loss; the browser only supplies the
 * date, optional proceeds, optional reason, and the proceeds account.
 */
export function DisposeDialog({ open, asset, companyId, onClose, onDone }: DisposeDialogProps) {
  const accountsState = useAccountOptions(companyId, true);
  const accounts = accountsState.status === "ready" ? accountsState.items : [];

  const [form, setForm] = useState<DisposeForm>(() => ({
    disposal_date: todayDateOnly(),
    proceeds: "",
    reason: "",
    proceeds_account_id: "",
  }));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const payload = useMemo<DisposeFixedAssetPayload>(
    () => ({
      disposal_date: form.disposal_date,
      proceeds: form.proceeds.trim() === "" ? null : form.proceeds.trim(),
      reason: form.reason.trim() === "" ? null : form.reason.trim(),
      proceeds_account_id: Number.parseInt(form.proceeds_account_id, 10) || 0,
    }),
    [form],
  );

  const set = (key: keyof DisposeForm, value: string) => {
    setForm((current) => ({ ...current, [key]: value }));
    setErrors((current) => {
      if (!(key in current)) return current;
      const next = { ...current };
      delete next[key];
      return next;
    });
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;

    const clientErrors = validateDispose(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setError(null);
    try {
      const raw = await disposeFixedAsset(asset.id, payload);
      const parsed = parseDisposeResponse(raw);
      if (parsed === null) {
        setError("The server returned an unexpected disposal response.");
        setSubmitting(false);
        return;
      }
      onDone(parsed);
    } catch (caught) {
      if (caught instanceof ApiError) {
        setErrors(firstFieldErrors(caught.errors));
        setError(caught.message);
      } else {
        setError("We could not dispose of this asset.");
      }
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title="Dispose of asset"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="fa-dispose-form" variant="danger" loading={submitting}>
            Dispose
          </Button>
        </>
      }
    >
      <form id="fa-dispose-form" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <ErrorBanner message={error} />
        <p className="text-sm text-muted-foreground">
          Disposing of <span className="font-medium text-foreground">{asset.asset_number}</span> removes it from
          the register. Current net book value: {money(asset.carrying_value)}.
        </p>

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Disposal date" htmlFor="fa-dispose-date" required error={errors.disposal_date}>
            <Input
              id="fa-dispose-date"
              type="date"
              value={form.disposal_date}
              onChange={(event) => set("disposal_date", event.target.value)}
              invalid={Boolean(errors.disposal_date)}
            />
          </FormField>

          <FormField
            label="Proceeds"
            htmlFor="fa-dispose-proceeds"
            error={errors.proceeds}
            hint="Optional. Leave blank for no proceeds."
          >
            <Input
              id="fa-dispose-proceeds"
              inputMode="decimal"
              value={form.proceeds}
              onChange={(event) => set("proceeds", event.target.value)}
              invalid={Boolean(errors.proceeds)}
              autoComplete="off"
            />
          </FormField>
        </div>

        <FormField
          label="Proceeds account"
          htmlFor="fa-dispose-account"
          required
          error={errors.proceeds_account_id}
          hint="The account that receives any disposal proceeds."
        >
          <select
            id="fa-dispose-account"
            className={selectClasses}
            value={form.proceeds_account_id}
            onChange={(event) => set("proceeds_account_id", event.target.value)}
            disabled={accountsState.status !== "ready"}
          >
            <option value="">Select an account…</option>
            {accounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.code} — {account.name}
                {account.is_active ? "" : " (inactive)"}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Reason" htmlFor="fa-dispose-reason" error={errors.reason}>
          <textarea
            id="fa-dispose-reason"
            className={textareaClasses}
            value={form.reason}
            onChange={(event) => set("reason", event.target.value)}
          />
        </FormField>
      </form>
    </Dialog>
  );
}

type DeleteFixedAssetDialogProps = {
  open: boolean;
  asset: FixedAsset;
  onClose: () => void;
  onDeleted: () => void;
};

/** Delete a draft asset. The backend refuses once the asset is capitalised. */
export function DeleteFixedAssetDialog({ open, asset, onClose, onDeleted }: DeleteFixedAssetDialogProps) {
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    try {
      await deleteFixedAsset(asset.id);
      onDeleted();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "We could not delete this asset.");
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title="Delete asset"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button variant="danger" onClick={submit} loading={submitting}>
            Delete asset
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3 text-sm text-muted-foreground">
        <ErrorBanner message={error} />
        <p>
          Delete <span className="font-medium text-foreground">{asset.asset_number}</span> — {asset.name}? This
          cannot be undone. Only draft assets can be deleted.
        </p>
      </div>
    </Dialog>
  );
}
