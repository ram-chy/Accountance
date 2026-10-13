"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FormField, Input } from "@/components/ui/field";
import { useAccountOptions } from "@/lib/accounts/use-account-options";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { createSupplier, updateSupplier } from "@/lib/suppliers/api";
import type { Supplier, SupplierWritePayload } from "@/lib/suppliers/types";
import { parseSupplierOrThrow, validateSupplierWrite } from "@/lib/suppliers/validators";

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export const EMPTY_SUPPLIER_FORM: SupplierWritePayload = {
  supplier_code: "",
  name: "",
  email: null,
  phone: null,
  address: null,
  city: null,
  state: null,
  country_code: null,
  tax_identifier: null,
  payable_account_id: null,
};

function toForm(supplier: Supplier): SupplierWritePayload {
  return {
    supplier_code: supplier.supplier_code,
    name: supplier.name,
    email: supplier.email,
    phone: supplier.phone,
    address: supplier.address,
    city: supplier.city,
    state: supplier.state,
    country_code: supplier.country_code,
    tax_identifier: supplier.tax_identifier,
    payable_account_id: supplier.payable_account_id,
  };
}

function orNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

type SupplierFormDialogProps = {
  open: boolean;
  companyId: number | null;
  /** Present when editing; absent when creating. */
  supplier?: Supplier | null;
  onClose: () => void;
  onSaved: (supplier: Supplier) => void;
};

/**
 * Create/edit a supplier. No `company_id`, `is_active` or other server-owned
 * field is ever submitted. Server validation maps to the relevant field.
 */
export function SupplierFormDialog({
  open,
  companyId,
  supplier,
  onClose,
  onSaved,
}: SupplierFormDialogProps) {
  const isEdit = Boolean(supplier);
  const accountsState = useAccountOptions(companyId, true);
  const accounts = accountsState.status === "ready" ? accountsState.items : [];

  const [form, setForm] = useState<SupplierWritePayload>(() => (supplier ? toForm(supplier) : EMPTY_SUPPLIER_FORM));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const set = <K extends keyof SupplierWritePayload>(key: K, value: SupplierWritePayload[K]) => {
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

    const payload: SupplierWritePayload = {
      ...form,
      supplier_code: form.supplier_code.trim(),
      name: form.name.trim(),
    };

    const clientErrors = validateSupplierWrite(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const raw = isEdit && supplier
        ? await updateSupplier(supplier.id, payload)
        : await createSupplier(payload);
      const saved = parseSupplierOrThrow(raw);
      onSaved(saved);
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this supplier. Please try again.");
      }
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      title={isEdit ? "Edit supplier" : "New supplier"}
      onClose={onClose}
      footer={
        <>
          <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" form="supplier-form" loading={submitting}>
            {isEdit ? "Save changes" : "Create supplier"}
          </Button>
        </>
      }
    >
      <form id="supplier-form" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {formError ? (
          <p role="alert" className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground">
            {formError}
          </p>
        ) : null}

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Supplier code" htmlFor="supplier-code" required error={errors.supplier_code}>
            <Input
              id="supplier-code"
              value={form.supplier_code}
              onChange={(event) => set("supplier_code", event.target.value)}
              invalid={Boolean(errors.supplier_code)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Name" htmlFor="supplier-name" required error={errors.name}>
            <Input
              id="supplier-name"
              value={form.name}
              onChange={(event) => set("name", event.target.value)}
              invalid={Boolean(errors.name)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Email" htmlFor="supplier-email" error={errors.email}>
            <Input
              id="supplier-email"
              type="email"
              value={form.email ?? ""}
              onChange={(event) => set("email", orNull(event.target.value))}
              invalid={Boolean(errors.email)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Phone" htmlFor="supplier-phone" error={errors.phone}>
            <Input
              id="supplier-phone"
              value={form.phone ?? ""}
              onChange={(event) => set("phone", orNull(event.target.value))}
              invalid={Boolean(errors.phone)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Tax identifier" htmlFor="supplier-tax" error={errors.tax_identifier}>
            <Input
              id="supplier-tax"
              value={form.tax_identifier ?? ""}
              onChange={(event) => set("tax_identifier", orNull(event.target.value))}
              invalid={Boolean(errors.tax_identifier)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="Country code" htmlFor="supplier-country" error={errors.country_code} hint="Two-letter ISO code.">
            <Input
              id="supplier-country"
              value={form.country_code ?? ""}
              maxLength={2}
              onChange={(event) => set("country_code", orNull(event.target.value.toUpperCase()))}
              invalid={Boolean(errors.country_code)}
              autoComplete="off"
            />
          </FormField>
        </div>

        <FormField label="Address" htmlFor="supplier-address" error={errors.address}>
          <Input
            id="supplier-address"
            value={form.address ?? ""}
            onChange={(event) => set("address", orNull(event.target.value))}
            invalid={Boolean(errors.address)}
            autoComplete="off"
          />
        </FormField>

        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="City" htmlFor="supplier-city" error={errors.city}>
            <Input
              id="supplier-city"
              value={form.city ?? ""}
              onChange={(event) => set("city", orNull(event.target.value))}
              invalid={Boolean(errors.city)}
              autoComplete="off"
            />
          </FormField>

          <FormField label="State/Region" htmlFor="supplier-state" error={errors.state}>
            <Input
              id="supplier-state"
              value={form.state ?? ""}
              onChange={(event) => set("state", orNull(event.target.value))}
              invalid={Boolean(errors.state)}
              autoComplete="off"
            />
          </FormField>
        </div>

        <FormField
          label="Payable account"
          htmlFor="supplier-payable"
          required
          error={errors.payable_account_id}
          hint="The liability account this supplier's payables post to."
        >
          <select
            id="supplier-payable"
            className={selectClasses}
            value={form.payable_account_id === null ? "" : String(form.payable_account_id)}
            onChange={(event) =>
              set("payable_account_id", event.target.value === "" ? null : Number(event.target.value))
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
