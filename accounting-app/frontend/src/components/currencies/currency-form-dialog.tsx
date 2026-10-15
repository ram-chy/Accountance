"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FormField, Input } from "@/components/ui/field";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { createCurrency, updateCurrency } from "@/lib/currencies/api";
import type {
  Currency,
  CurrencyCreatePayload,
  CurrencyUpdatePayload,
} from "@/lib/currencies/types";
import {
  DEFAULT_CURRENCY_DECIMAL_PRECISION,
  MAX_CURRENCY_DECIMAL_PRECISION,
  MIN_CURRENCY_DECIMAL_PRECISION,
} from "@/lib/currencies/types";
import {
  parseCurrencyOrThrow,
  validateCurrencyCreate,
  validateCurrencyUpdate,
} from "@/lib/currencies/validators";

/**
 * Create / edit currency dialog.
 *
 * Fields mirror `StoreCurrencyRequest` / `UpdateCurrencyRequest` exactly: code,
 * name, optional symbol and decimal precision. `is_active` is deliberately
 * absent — activation is a separate, separately-audited route, so a new currency
 * is active by definition and an existing one is never toggled here. Server-owned
 * columns (id, timestamps) are never sent.
 */

type CurrencyForm = {
  code: string;
  name: string;
  symbol: string;
  decimal_precision: string;
};

const EMPTY_FORM: CurrencyForm = {
  code: "",
  name: "",
  symbol: "",
  decimal_precision: String(DEFAULT_CURRENCY_DECIMAL_PRECISION),
};

function toForm(currency: Currency): CurrencyForm {
  return {
    code: currency.code,
    name: currency.name,
    symbol: currency.symbol ?? "",
    decimal_precision: String(currency.decimal_precision),
  };
}

function orNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function toPrecision(value: string): number {
  const parsed = Number.parseInt(value, 10);
  return Number.isInteger(parsed) ? parsed : DEFAULT_CURRENCY_DECIMAL_PRECISION;
}

type CurrencyFormDialogProps = {
  open: boolean;
  /** Present when editing; absent when creating. */
  currency?: Currency | null;
  onClose: () => void;
  onSaved: (currency: Currency) => void;
};

export function CurrencyFormDialog({ open, currency, onClose, onSaved }: CurrencyFormDialogProps) {
  const isEdit = Boolean(currency);

  const [form, setForm] = useState<CurrencyForm>(() => (currency ? toForm(currency) : EMPTY_FORM));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const set = <K extends keyof CurrencyForm>(key: K, value: CurrencyForm[K]) => {
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

    const base = {
      code: form.code.trim(),
      name: form.name.trim(),
      symbol: orNull(form.symbol),
      decimal_precision: toPrecision(form.decimal_precision),
    };

    const clientErrors = isEdit
      ? validateCurrencyUpdate(base as CurrencyUpdatePayload)
      : validateCurrencyCreate(base as CurrencyCreatePayload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const raw =
        isEdit && currency
          ? await updateCurrency(currency.id, base as CurrencyUpdatePayload)
          : await createCurrency(base as CurrencyCreatePayload);
      onSaved(parseCurrencyOrThrow(raw));
      onClose();
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this currency. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title={isEdit ? "Edit currency" : "New currency"}
      description={
        isEdit
          ? "Correct the currency's descriptive details. Use the lifecycle controls to activate or deactivate it."
          : "Currencies are global reference data shared by every company."
      }
      onClose={onClose}
      footer={
        <>
          <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="currency-form" loading={submitting}>
            {isEdit ? "Save changes" : "Create currency"}
          </Button>
        </>
      }
    >
      <form id="currency-form" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {formError ? (
          <p
            role="alert"
            className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground"
          >
            {formError}
          </p>
        ) : null}

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Code" htmlFor="currency-code" required error={errors.code} hint="Three letters, e.g. USD.">
            <Input
              id="currency-code"
              value={form.code}
              maxLength={3}
              autoComplete="off"
              onChange={(event) => set("code", event.target.value.toUpperCase())}
              invalid={Boolean(errors.code)}
            />
          </FormField>

          <FormField label="Symbol" htmlFor="currency-symbol" error={errors.symbol} hint="Optional, e.g. $ or ₹.">
            <Input
              id="currency-symbol"
              value={form.symbol}
              maxLength={10}
              autoComplete="off"
              onChange={(event) => set("symbol", event.target.value)}
              invalid={Boolean(errors.symbol)}
            />
          </FormField>
        </div>

        <FormField label="Name" htmlFor="currency-name" required error={errors.name}>
          <Input
            id="currency-name"
            value={form.name}
            maxLength={100}
            autoComplete="off"
            onChange={(event) => set("name", event.target.value)}
            invalid={Boolean(errors.name)}
          />
        </FormField>

        <FormField
          label="Decimal places"
          htmlFor="currency-precision"
          error={errors.decimal_precision}
          hint={`How many decimal places amounts in this currency carry (${MIN_CURRENCY_DECIMAL_PRECISION}–${MAX_CURRENCY_DECIMAL_PRECISION}).`}
        >
          <Input
            id="currency-precision"
            type="number"
            min={MIN_CURRENCY_DECIMAL_PRECISION}
            max={MAX_CURRENCY_DECIMAL_PRECISION}
            step={1}
            value={form.decimal_precision}
            onChange={(event) => set("decimal_precision", event.target.value)}
            invalid={Boolean(errors.decimal_precision)}
          />
        </FormField>
      </form>
    </Dialog>
  );
}
