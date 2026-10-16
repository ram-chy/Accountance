"use client";

import { useState, type FormEvent } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { updateCompanySettings } from "@/lib/company/api";
import type { CompanySettings, CompanySettingsUpdatePayload } from "@/lib/company/types";
import {
  MAX_PAYMENT_TERMS_DAYS,
  parseCompanySettingsOrThrow,
  validateCompanySettings,
} from "@/lib/company/validators";
import { useCurrencyOptions } from "@/lib/currencies/use-currency-options";

/**
 * Company system settings form (numbering prefixes, payment terms, default
 * document currency and fiscal-year start month).
 *
 * Backed by `PUT /company/settings`, which acts only on the resolved company
 * context — no company id is ever sent. The prefixes and the default currency
 * are `nullable`, so an emptied field is sent as `null` to clear it; payment
 * terms and the fiscal start month are `sometimes` integers, so they are
 * omitted (rather than sent as null) when left blank, leaving them unchanged.
 *
 * The currency picker offers the company's active currencies. Its value is the
 * *default document currency*, not the company's functional base currency:
 * changing the base currency is a separate, unreadable-from-here act and is not
 * offered (see the Phase 20 report).
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

const MONTHS = [
  "January", "February", "March", "April", "May", "June",
  "July", "August", "September", "October", "November", "December",
];

export function CompanySettingsForm({
  companyId,
  settings,
  canViewCurrencies,
  onSaved,
}: {
  companyId: number;
  settings: CompanySettings;
  canViewCurrencies: boolean;
  onSaved: () => void;
}) {
  const currencyOptions = useCurrencyOptions(companyId, canViewCurrencies);
  const currenciesReady = currencyOptions.status === "ready";

  const [invoicePrefix, setInvoicePrefix] = useState(settings.invoice_number_prefix ?? "");
  const [quotationPrefix, setQuotationPrefix] = useState(
    settings.quotation_number_prefix ?? "",
  );
  const [paymentTerms, setPaymentTerms] = useState(
    settings.default_payment_terms_days === null
      ? ""
      : String(settings.default_payment_terms_days),
  );
  const [currencyId, setCurrencyId] = useState(
    settings.default_currency_id === null ? "" : String(settings.default_currency_id),
  );
  const [fiscalMonth, setFiscalMonth] = useState(
    settings.default_fiscal_year_start_month === null
      ? ""
      : String(settings.default_fiscal_year_start_month),
  );

  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (saving) return;

    const payload: CompanySettingsUpdatePayload = {
      invoice_number_prefix: invoicePrefix.trim() === "" ? null : invoicePrefix.trim(),
      quotation_number_prefix:
        quotationPrefix.trim() === "" ? null : quotationPrefix.trim(),
    };

    if (paymentTerms.trim() !== "") {
      payload.default_payment_terms_days = Number.parseInt(paymentTerms, 10);
    }
    if (fiscalMonth !== "") {
      payload.default_fiscal_year_start_month = Number.parseInt(fiscalMonth, 10);
    }

    payload.default_currency_id = currencyId === "" ? null : Number.parseInt(currencyId, 10);

    const clientErrors = validateCompanySettings(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSaving(true);
    setErrors({});
    setFormError(null);

    try {
      parseCompanySettingsOrThrow(await updateCompanySettings(payload));
      toast.success("Company settings saved.");
      onSaved();
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save the company settings. Please try again.");
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {formError ? (
        <ErrorState title="Could not save company settings" message={formError} />
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField
          label="Invoice number prefix"
          htmlFor="settings-invoice-prefix"
          error={errors.invoice_number_prefix}
          hint="Letters, numbers, hyphens and slashes, up to 20 characters. Leave blank for none."
        >
          <Input
            id="settings-invoice-prefix"
            value={invoicePrefix}
            onChange={(event) => setInvoicePrefix(event.target.value)}
            invalid={Boolean(errors.invoice_number_prefix)}
            maxLength={20}
          />
        </FormField>

        <FormField
          label="Quotation number prefix"
          htmlFor="settings-quotation-prefix"
          error={errors.quotation_number_prefix}
          hint="Letters, numbers, hyphens and slashes, up to 20 characters. Leave blank for none."
        >
          <Input
            id="settings-quotation-prefix"
            value={quotationPrefix}
            onChange={(event) => setQuotationPrefix(event.target.value)}
            invalid={Boolean(errors.quotation_number_prefix)}
            maxLength={20}
          />
        </FormField>

        <FormField
          label="Default payment terms (days)"
          htmlFor="settings-payment-terms"
          error={errors.default_payment_terms_days}
          hint={`Whole number of days (0–${MAX_PAYMENT_TERMS_DAYS}). Leave blank to leave unchanged.`}
        >
          <Input
            id="settings-payment-terms"
            type="number"
            min={0}
            max={MAX_PAYMENT_TERMS_DAYS}
            inputMode="numeric"
            value={paymentTerms}
            onChange={(event) => setPaymentTerms(event.target.value)}
            invalid={Boolean(errors.default_payment_terms_days)}
          />
        </FormField>

        <FormField
          label="Fiscal year starts in"
          htmlFor="settings-fiscal-month"
          error={errors.default_fiscal_year_start_month}
          hint="Leave blank to leave unchanged."
        >
          <select
            id="settings-fiscal-month"
            className={selectClasses}
            value={fiscalMonth}
            onChange={(event) => setFiscalMonth(event.target.value)}
            aria-invalid={Boolean(errors.default_fiscal_year_start_month) || undefined}
          >
            <option value="">Not set</option>
            {MONTHS.map((month, index) => (
              <option key={month} value={index + 1}>
                {month}
              </option>
            ))}
          </select>
        </FormField>

        <FormField
          label="Default document currency"
          htmlFor="settings-default-currency"
          error={errors.default_currency_id}
          hint="Used to pre-select a currency on new documents. This is not the company's functional base currency."
        >
          {canViewCurrencies ? (
            <select
              id="settings-default-currency"
              className={selectClasses}
              value={currencyId}
              onChange={(event) => setCurrencyId(event.target.value)}
              disabled={!currenciesReady}
              aria-invalid={Boolean(errors.default_currency_id) || undefined}
            >
              <option value="">Not set</option>
              {currencyOptions.items.map((currency) => (
                <option key={currency.id} value={currency.id}>
                  {currency.code} — {currency.name}
                </option>
              ))}
            </select>
          ) : (
            <div className="text-sm text-muted-foreground">
              Your role cannot view currencies.
            </div>
          )}
        </FormField>
      </div>

      {canViewCurrencies && currencyOptions.status === "error" ? (
        <EmptyState
          title="Could not load currencies"
          description={currencyOptions.message}
          className="py-6"
        />
      ) : null}

      <div>
        <Button type="submit" loading={saving} disabled={canViewCurrencies && !currenciesReady}>
          {saving ? "Saving…" : "Save settings"}
        </Button>
      </div>
    </form>
  );
}
