"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FormField, Input } from "@/components/ui/field";
import { useAccountOptions } from "@/lib/accounts/use-account-options";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { createCustomer, updateCustomer } from "@/lib/customers/api";
import type { Customer, CustomerWritePayload } from "@/lib/customers/types";
import { parseCustomerOrThrow, validateCustomerWrite } from "@/lib/customers/validators";

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export const EMPTY_CUSTOMER_FORM: CustomerWritePayload = {
  customer_code: "",
  name: "",
  email: null,
  phone: null,
  address: null,
  city: null,
  state: null,
  country_code: null,
  tax_identifier: null,
  receivable_account_id: null,
};

function toForm(customer: Customer): CustomerWritePayload {
  return {
    customer_code: customer.customer_code,
    name: customer.name,
    email: customer.email,
    phone: customer.phone,
    address: customer.address,
    city: customer.city,
    state: customer.state,
    country_code: customer.country_code,
    tax_identifier: customer.tax_identifier,
    receivable_account_id: customer.receivable_account_id,
  };
}

function orNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

type CustomerFormDialogProps = {
  open: boolean;
  companyId: number | null;
  /** Present when editing; absent when creating. */
  customer?: Customer | null;
  onClose: () => void;
  onSaved: (customer: Customer) => void;
};

/**
 * Create/edit a customer. No `company_id`, `is_active` or other server-owned
 * field is ever submitted. Server validation maps to the relevant field.
 */
export function CustomerFormDialog({
  open,
  companyId,
  customer,
  onClose,
  onSaved,
}: CustomerFormDialogProps) {
  const isEdit = Boolean(customer);
  const accountsState = useAccountOptions(companyId, true);
  const accounts = accountsState.status === "ready" ? accountsState.items : [];

  const [form, setForm] = useState<CustomerWritePayload>(() => (customer ? toForm(customer) : EMPTY_CUSTOMER_FORM));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const set = <K extends keyof CustomerWritePayload>(key: K, value: CustomerWritePayload[K]) => {
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

    const payload: CustomerWritePayload = {
      ...form,
      customer_code: form.customer_code.trim(),
      name: form.name.trim(),
    };

    const clientErrors = validateCustomerWrite(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const raw = isEdit && customer
        ? await updateCustomer(customer.id, payload)
        : await createCustomer(payload);
      const saved = parseCustomerOrThrow(raw);
      onSaved(saved);
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this customer. Please try again.");
      }
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title={isEdit ? "Edit customer" : "New customer"}
      onClose={onClose}
      footer={
        <>
          <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="customer-form" loading={submitting}>
            {isEdit ? "Save changes" : "Create customer"}
          </Button>
        </>
      }
    >
      <form id="customer-form" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {formError ? (
          <p role="alert" className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground">
            {formError}
          </p>
        ) : null}

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Customer code" htmlFor="customer-code" required error={errors.customer_code}>
            <Input
              id="customer-code"
              value={form.customer_code}
              onChange={(event) => set("customer_code", event.target.value)}
              invalid={Boolean(errors.customer_code)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Name" htmlFor="customer-name" required error={errors.name}>
            <Input
              id="customer-name"
              value={form.name}
              onChange={(event) => set("name", event.target.value)}
              invalid={Boolean(errors.name)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Email" htmlFor="customer-email" error={errors.email}>
            <Input
              id="customer-email"
              type="email"
              value={form.email ?? ""}
              onChange={(event) => set("email", orNull(event.target.value))}
              invalid={Boolean(errors.email)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Phone" htmlFor="customer-phone" error={errors.phone}>
            <Input
              id="customer-phone"
              value={form.phone ?? ""}
              onChange={(event) => set("phone", orNull(event.target.value))}
              invalid={Boolean(errors.phone)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Tax identifier" htmlFor="customer-tax" error={errors.tax_identifier}>
            <Input
              id="customer-tax"
              value={form.tax_identifier ?? ""}
              onChange={(event) => set("tax_identifier", orNull(event.target.value))}
              invalid={Boolean(errors.tax_identifier)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Country code" htmlFor="customer-country" error={errors.country_code} hint="Two-letter ISO code.">
            <Input
              id="customer-country"
              value={form.country_code ?? ""}
              maxLength={2}
              onChange={(event) => set("country_code", orNull(event.target.value.toUpperCase()))}
              invalid={Boolean(errors.country_code)}
              autoComplete="off"
            />
          </FormField>
        </div>

        <FormField label="Address" htmlFor="customer-address" error={errors.address}>
          <Input
            id="customer-address"
            value={form.address ?? ""}
            onChange={(event) => set("address", orNull(event.target.value))}
            invalid={Boolean(errors.address)}
            autoComplete="off"
          />
        </FormField>

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="City" htmlFor="customer-city" error={errors.city}>
            <Input
              id="customer-city"
              value={form.city ?? ""}
              onChange={(event) => set("city", orNull(event.target.value))}
              invalid={Boolean(errors.city)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="State/Region" htmlFor="customer-state" error={errors.state}>
            <Input
              id="customer-state"
              value={form.state ?? ""}
              onChange={(event) => set("state", orNull(event.target.value))}
              invalid={Boolean(errors.state)}
              autoComplete="off"
            />
          </FormField>
        </div>

        <FormField
          label="Receivable account"
          htmlFor="customer-receivable"
          required
          error={errors.receivable_account_id}
          hint="The asset account this customer's receivables post to."
        >
          <select
            id="customer-receivable"
            className={selectClasses}
            value={form.receivable_account_id === null ? "" : String(form.receivable_account_id)}
            onChange={(event) =>
              set("receivable_account_id", event.target.value === "" ? null : Number(event.target.value))
            }
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
      </form>
    </Dialog>
  );
}
