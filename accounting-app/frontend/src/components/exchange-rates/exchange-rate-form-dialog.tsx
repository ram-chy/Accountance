"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FormField, Input } from "@/components/ui/field";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { useCurrencyOptions } from "@/lib/currencies/use-currency-options";
import { createExchangeRate, updateExchangeRate } from "@/lib/exchange-rates/api";
import type {
  ExchangeRate,
  ExchangeRateCreatePayload,
  ExchangeRateUpdatePayload,
} from "@/lib/exchange-rates/types";
import {
  parseExchangeRateOrThrow,
  validateExchangeRateCreate,
  validateExchangeRateUpdate,
} from "@/lib/exchange-rates/validators";
import { todayDateOnly } from "@/lib/format/date";

/**
 * Create / edit exchange-rate dialog.
 *
 * Create mirrors `StoreExchangeRateRequest`: a from/to pair, a positive decimal
 * rate, an effective date and an optional source. Edit mirrors
 * `UpdateExchangeRateRequest`: the pair is immutable (a rate is history), so only
 * the value, date and source may change — and the backend refuses any edit to a
 * rate that has already priced a document, surfacing that refusal verbatim.
 *
 * The direction is stated in the field hint: the rate is units of the to
 * currency per ONE unit of the from currency.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

type ExchangeRateForm = {
  from_currency_id: string;
  to_currency_id: string;
  rate: string;
  effective_date: string;
  source: string;
};

function emptyForm(): ExchangeRateForm {
  return {
    from_currency_id: "",
    to_currency_id: "",
    rate: "",
    effective_date: todayDateOnly(),
    source: "",
  };
}

function toForm(rate: ExchangeRate): ExchangeRateForm {
  return {
    from_currency_id: String(rate.from_currency_id),
    to_currency_id: String(rate.to_currency_id),
    rate: rate.rate,
    effective_date: rate.effective_date,
    source: rate.source ?? "",
  };
}

function toId(value: string): number {
  const parsed = Number.parseInt(value, 10);
  return Number.isInteger(parsed) ? parsed : 0;
}

function orNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

type ExchangeRateFormDialogProps = {
  open: boolean;
  companyId: number | null;
  /** Present when editing; absent when creating. */
  rate?: ExchangeRate | null;
  onClose: () => void;
  onSaved: (rate: ExchangeRate) => void;
};

export function ExchangeRateFormDialog({
  open,
  companyId,
  rate,
  onClose,
  onSaved,
}: ExchangeRateFormDialogProps) {
  const isEdit = Boolean(rate);
  const options = useCurrencyOptions(companyId, open);
  const currencies = options.status === "ready" ? options.items : [];

  const [form, setForm] = useState<ExchangeRateForm>(() => (rate ? toForm(rate) : emptyForm()));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const set = <K extends keyof ExchangeRateForm>(key: K, value: ExchangeRateForm[K]) => {
    setForm((current) => ({ ...current, [key]: value }));
    setErrors((current) => {
      if (!(key in current)) return current;
      const next = { ...current };
      delete next[key as string];
      return next;
    });
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;

    if (isEdit && rate) {
      const payload: ExchangeRateUpdatePayload = {
        rate: form.rate.trim(),
        effective_date: form.effective_date,
        source: orNull(form.source),
      };

      const clientErrors = validateExchangeRateUpdate(payload);
      if (Object.keys(clientErrors).length > 0) {
        setErrors(clientErrors);
        setFormError("Please correct the highlighted fields.");
        return;
      }

      setSubmitting(true);
      setFormError(null);

      try {
        onSaved(parseExchangeRateOrThrow(await updateExchangeRate(rate.id, payload)));
        onClose();
      } catch (error) {
        if (error instanceof ApiError) {
          setErrors(firstFieldErrors(error.errors));
          setFormError(error.message);
        } else {
          setFormError("We could not save this rate. Please try again.");
        }
      } finally {
        setSubmitting(false);
      }

      return;
    }

    const payload: ExchangeRateCreatePayload = {
      from_currency_id: toId(form.from_currency_id),
      to_currency_id: toId(form.to_currency_id),
      rate: form.rate.trim(),
      effective_date: form.effective_date,
      source: orNull(form.source),
    };

    const clientErrors = validateExchangeRateCreate(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      onSaved(parseExchangeRateOrThrow(await createExchangeRate(payload)));
      onClose();
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this rate. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  const fromCode = rate?.from_currency ?? null;
  const toCode = rate?.to_currency ?? null;

  return (
    <Dialog
      open={open}
      title={isEdit ? "Edit exchange rate" : "New exchange rate"}
      description={
        isEdit
          ? "A rate is dated history, so its currency pair cannot change. Correcting a rate that has priced a document is refused — record the correction as a new effective date instead."
          : "A rate is dated history: one row per currency pair per day. To change a rate, add a newer effective date rather than editing an older one."
      }
      onClose={onClose}
      footer={
        <>
          <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="exchange-rate-form" loading={submitting}>
            {isEdit ? "Save changes" : "Create rate"}
          </Button>
        </>
      }
    >
      <form id="exchange-rate-form" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {formError ? (
          <p
            role="alert"
            className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
          >
            {formError}
          </p>
        ) : null}

        {isEdit ? (
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="From currency" htmlFor="exchange-rate-from-fixed">
              <Input id="exchange-rate-from-fixed" value={fromCode ?? `#${rate?.from_currency_id ?? ""}`} readOnly disabled />
            </FormField>
            <FormField label="To currency" htmlFor="exchange-rate-to-fixed">
              <Input id="exchange-rate-to-fixed" value={toCode ?? `#${rate?.to_currency_id ?? ""}`} readOnly disabled />
            </FormField>
          </div>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="From currency" htmlFor="exchange-rate-from" required error={errors.from_currency_id}>
              <select
                id="exchange-rate-from"
                className={selectClasses}
                value={form.from_currency_id}
                onChange={(event) => set("from_currency_id", event.target.value)}
                disabled={options.status !== "ready"}
              >
                <option value="">Select a currency…</option>
                {currencies.map((currency) => (
                  <option key={currency.id} value={currency.id}>
                    {currency.code} — {currency.name}
                  </option>
                ))}
              </select>
            </FormField>

            <FormField label="To currency" htmlFor="exchange-rate-to" required error={errors.to_currency_id}>
              <select
                id="exchange-rate-to"
                className={selectClasses}
                value={form.to_currency_id}
                onChange={(event) => set("to_currency_id", event.target.value)}
                disabled={options.status !== "ready"}
              >
                <option value="">Select a currency…</option>
                {currencies.map((currency) => (
                  <option key={currency.id} value={currency.id}>
                    {currency.code} — {currency.name}
                  </option>
                ))}
              </select>
            </FormField>
          </div>
        )}

        {options.status === "error" ? (
          <p role="alert" className="text-sm text-danger-soft-foreground">
            {options.message}
          </p>
        ) : null}

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField
            label="Rate"
            htmlFor="exchange-rate-rate"
            required
            error={errors.rate}
            hint="Units of the to currency per ONE unit of the from currency."
          >
            <Input
              id="exchange-rate-rate"
              value={form.rate}
              inputMode="decimal"
              autoComplete="off"
              placeholder="e.g. 83.5000000000"
              onChange={(event) => set("rate", event.target.value)}
              invalid={Boolean(errors.rate)}
            />
          </FormField>

          <FormField label="Effective date" htmlFor="exchange-rate-date" required error={errors.effective_date}>
            <Input
              id="exchange-rate-date"
              type="date"
              value={form.effective_date}
              onChange={(event) => set("effective_date", event.target.value)}
              invalid={Boolean(errors.effective_date)}
            />
          </FormField>
        </div>

        <FormField label="Source" htmlFor="exchange-rate-source" error={errors.source} hint="Optional, e.g. the rate provider.">
          <Input
            id="exchange-rate-source"
            value={form.source}
            maxLength={100}
            autoComplete="off"
            onChange={(event) => set("source", event.target.value)}
            invalid={Boolean(errors.source)}
          />
        </FormField>
      </form>
    </Dialog>
  );
}
