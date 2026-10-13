"use client";

import { useRouter } from "next/navigation";
import { useMemo, useState, type FormEvent } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { useCashBankAccountOptions } from "@/lib/cash-bank/use-cash-bank-accounts";
import { createCustomerReceipt, updateCustomerReceipt } from "@/lib/customer-receipts/api";
import { useCustomerReceipt } from "@/lib/customer-receipts/use-customer-receipt";
import { useOutstandingInvoices } from "@/lib/customer-receipts/use-outstanding-invoices";
import type {
  CustomerReceipt,
  CustomerReceiptWritePayload,
  ReceiptAllocationPayload,
} from "@/lib/customer-receipts/types";
import { parseCustomerReceiptOrThrow, validateCustomerReceiptWrite } from "@/lib/customer-receipts/validators";
import { useCustomerOptions } from "@/lib/customers/use-customer-options";
import { formatDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";

const inputClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

const textareaClasses =
  "min-h-20 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled";

type InvoiceOption = {
  id: number;
  invoice_number: string;
  invoice_date: string | null;
  due_date: string | null;
  grand_total: string | null;
  balance_due: string | null;
};

const EMPTY_FORM: CustomerReceiptWritePayload = {
  customer_id: null,
  receipt_date: "",
  amount: "",
  payment_account_id: null,
  reference: null,
  notes: null,
  allocations: [],
};

function orNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function seedForm(receipt: CustomerReceipt | null): CustomerReceiptWritePayload {
  if (receipt === null) {
    return EMPTY_FORM;
  }

  return {
    customer_id: receipt.customer_id,
    receipt_date: receipt.receipt_date,
    amount: receipt.amount,
    payment_account_id: receipt.payment_account_id,
    reference: receipt.reference,
    notes: receipt.notes,
    allocations: receipt.allocations.map((allocation) => ({
      sales_invoice_id: allocation.sales_invoice_id,
      amount: allocation.amount,
    })),
  };
}

/**
 * Create/edit shell.
 *
 * Load / permission / status gating lives here; the editor owns the form state
 * and is remounted with a `key` once the receipt is known, so the allocation
 * editor seeds from the loaded draft without an effect writing state.
 */
export function CustomerReceiptForm({ receiptId }: { receiptId?: number }) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const isEdit = receiptId !== undefined;
  const canCreate = can("customer.receipts.create");
  const canUpdate = can("customer.receipts.update");

  const detail = useCustomerReceipt({
    receiptId: receiptId ?? 0,
    companyId,
    enabled: isEdit,
    reloadToken: 0,
  });

  if (!activeCompany) {
    return <EmptyState title="No company selected" description="Choose a company to record receipts." />;
  }
  if (isEdit && !canUpdate) {
    return <EmptyState title="Receipts unavailable" description="Your role does not include editing customer receipts." />;
  }
  if (!isEdit && !canCreate) {
    return <EmptyState title="Receipts unavailable" description="Your role does not include creating customer receipts." />;
  }
  if (isEdit && detail.status === "loading") {
    return <LoadingState label="Loading receipt…" />;
  }
  if (isEdit && detail.status === "notfound") {
    return <EmptyState title="Receipt not found" description="This receipt does not exist for the selected company." />;
  }
  if (isEdit && (detail.status === "error" || detail.status === "unavailable")) {
    return <ErrorState message={detail.message} requestId={detail.status === "error" ? detail.requestId : undefined} />;
  }
  if (isEdit && detail.status === "ready" && detail.data.status !== "DRAFT") {
    return (
      <EmptyState
        title="Receipt cannot be edited"
        description="Only draft receipts can be edited. Posted receipts are immutable."
      />
    );
  }

  return (
    <CustomerReceiptEditor
      key={isEdit ? `edit-${receiptId}` : "new"}
      receiptId={receiptId}
      receipt={isEdit && detail.status === "ready" ? detail.data : null}
      companyId={companyId}
      isEdit={isEdit}
    />
  );
}

type CustomerReceiptEditorProps = {
  receiptId?: number;
  receipt: CustomerReceipt | null;
  companyId: number | null;
  isEdit: boolean;
};

function CustomerReceiptEditor({ receiptId, receipt, companyId, isEdit }: CustomerReceiptEditorProps) {
  const router = useRouter();

  const customersState = useCustomerOptions(companyId, true);
  const customers = customersState.status === "ready"
    ? customersState.items.filter((customer) => customer.is_active)
    : [];
  const accountsState = useCashBankAccountOptions(companyId, true);
  const accounts = accountsState.status === "ready" ? accountsState.items : [];

  const [form, setForm] = useState<CustomerReceiptWritePayload>(() => seedForm(receipt));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const outstanding = useOutstandingInvoices(companyId, form.customer_id, true);

  const invoices = useMemo<InvoiceOption[]>(() => {
    const merged = new Map<number, InvoiceOption>();

    if (outstanding.status === "ready") {
      for (const invoice of outstanding.items) {
        merged.set(invoice.id, {
          id: invoice.id,
          invoice_number: invoice.invoice_number,
          invoice_date: invoice.invoice_date,
          due_date: invoice.due_date,
          grand_total: invoice.grand_total,
          balance_due: invoice.balance_due ?? null,
        });
      }
    }

    if (isEdit && receipt) {
      for (const allocation of receipt.allocations) {
        const ref = allocation.sales_invoice;
        if (ref && !merged.has(ref.id)) {
          merged.set(ref.id, {
            id: ref.id,
            invoice_number: ref.invoice_number,
            invoice_date: null,
            due_date: null,
            grand_total: ref.grand_total ?? null,
            balance_due: ref.balance_due ?? null,
          });
        } else if (!ref && !merged.has(allocation.sales_invoice_id)) {
          merged.set(allocation.sales_invoice_id, {
            id: allocation.sales_invoice_id,
            invoice_number: `#${allocation.sales_invoice_id}`,
            invoice_date: null,
            due_date: null,
            grand_total: null,
            balance_due: null,
          });
        }
      }
    }

    return Array.from(merged.values()).sort((a, b) => a.invoice_number.localeCompare(b.invoice_number));
  }, [outstanding, isEdit, receipt]);

  const allocationAmount = (invoiceId: number): string =>
    form.allocations.find((allocation) => allocation.sales_invoice_id === invoiceId)?.amount ?? "";

  const setAllocation = (invoiceId: number, value: string) => {
    setForm((current) => {
      const others = current.allocations.filter((allocation) => allocation.sales_invoice_id !== invoiceId);
      const trimmed = value.trim();
      const allocations: ReceiptAllocationPayload[] =
        trimmed === ""
          ? others
          : [...others, { sales_invoice_id: invoiceId, amount: trimmed }];
      return { ...current, allocations };
    });
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;

    const payload: CustomerReceiptWritePayload = {
      ...form,
      amount: form.amount.trim(),
      allocations: form.allocations.map((allocation) => ({
        sales_invoice_id: allocation.sales_invoice_id,
        amount: allocation.amount.trim(),
      })),
    };

    const clientErrors = validateCustomerReceiptWrite(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const raw = isEdit && receiptId
        ? await updateCustomerReceipt(receiptId, payload)
        : await createCustomerReceipt(payload);
      const saved = parseCustomerReceiptOrThrow(raw);
      router.push(`/customer-receipts/${saved.id}`);
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this receipt. Please try again.");
      }
      setSubmitting(false);
    }
  };

  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  return (
    <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
      <div>
        <h1 className="text-2xl font-semibold text-foreground">
          {isEdit ? "Edit draft receipt" : "New customer receipt"}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Allocate the full receipt amount across the customer&apos;s outstanding invoices.
        </p>
      </div>

      {formError ? (
        <p role="alert" className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground">
          {formError}
        </p>
      ) : null}

      <div className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2">
        <FormField label="Customer" htmlFor="receipt-customer" required error={errors.customer_id}>
          <select
            id="receipt-customer"
            className={inputClasses}
            value={form.customer_id === null ? "" : String(form.customer_id)}
            onChange={(event) =>
              setForm((current) => ({
                ...current,
                customer_id: event.target.value === "" ? null : Number(event.target.value),
                allocations: [],
              }))
            }
            disabled={customersState.status !== "ready"}
          >
            <option value="">Select a customer…</option>
            {customers.map((customer) => (
              <option key={customer.id} value={customer.id}>
                {customer.customer_code} — {customer.name}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Payment account" htmlFor="receipt-account" required error={errors.payment_account_id} hint="A cash or bank account.">
          <select
            id="receipt-account"
            className={inputClasses}
            value={form.payment_account_id === null ? "" : String(form.payment_account_id)}
            onChange={(event) =>
              setForm((current) => ({
                ...current,
                payment_account_id: event.target.value === "" ? null : Number(event.target.value),
              }))
            }
            disabled={accountsState.status !== "ready"}
          >
            <option value="">Select an account…</option>
            {accounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.code} — {account.name}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Receipt date" htmlFor="receipt-date" required error={errors.receipt_date}>
          <Input
            id="receipt-date"
            type="date"
            value={form.receipt_date}
            onChange={(event) => setForm((current) => ({ ...current, receipt_date: event.target.value }))}
            invalid={Boolean(errors.receipt_date)}
          />
        </FormField>

        <FormField label="Amount received" htmlFor="receipt-amount" required error={errors.amount}>
          <Input
            id="receipt-amount"
            inputMode="decimal"
            value={form.amount}
            onChange={(event) => setForm((current) => ({ ...current, amount: event.target.value }))}
            invalid={Boolean(errors.amount)}
          />
        </FormField>

        <FormField label="Reference" htmlFor="receipt-reference" error={errors.reference}>
          <Input
            id="receipt-reference"
            value={form.reference ?? ""}
            onChange={(event) => setForm((current) => ({ ...current, reference: orNull(event.target.value) }))}
          />
        </FormField>

        <div className="sm:col-span-2">
          <FormField label="Notes" htmlFor="receipt-notes" error={errors.notes}>
            <textarea
              id="receipt-notes"
              className={textareaClasses}
              value={form.notes ?? ""}
              onChange={(event) => setForm((current) => ({ ...current, notes: orNull(event.target.value) }))}
            />
          </FormField>
        </div>
      </div>

      <div className="flex flex-col gap-3">
        <h2 className="text-lg font-medium">Allocation</h2>
        {errors.allocations ? <p role="alert" className="text-sm text-danger">{errors.allocations}</p> : null}

        {form.customer_id === null ? (
          <EmptyState title="Select a customer" description="Outstanding invoices appear once a customer is chosen." />
        ) : outstanding.status === "error" ? (
          <ErrorState message={outstanding.message ?? "Could not load outstanding invoices."} />
        ) : outstanding.status === "loading" ? (
          <LoadingState label="Loading outstanding invoices…" />
        ) : invoices.length === 0 ? (
          <EmptyState title="No outstanding invoices" description="This customer has no posted invoices with a balance due." />
        ) : (
          <div className="overflow-x-auto rounded-lg border border-border bg-surface">
            <table className="min-w-full divide-y divide-border text-sm">
              <thead className="bg-surface-muted">
                <tr>
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Invoice</th>
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Due</th>
                  <th className="px-3 py-2 text-right font-medium text-muted-foreground">Total</th>
                  <th className="px-3 py-2 text-right font-medium text-muted-foreground">Outstanding</th>
                  <th className="px-3 py-2 text-right font-medium text-muted-foreground">Allocate</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {invoices.map((invoice, index) => (
                  <tr key={invoice.id}>
                    <td className="px-3 py-2 font-mono text-xs">{invoice.invoice_number}</td>
                    <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(invoice.invoice_date)}</td>
                    <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(invoice.due_date)}</td>
                    <td className="px-3 py-2 text-right">{money(invoice.grand_total)}</td>
                    <td className="px-3 py-2 text-right">{money(invoice.balance_due)}</td>
                    <td className="px-3 py-2">
                      <Input
                        aria-label={`Amount to allocate to ${invoice.invoice_number}`}
                        inputMode="decimal"
                        value={allocationAmount(invoice.id)}
                        onChange={(event) => setAllocation(invoice.id, event.target.value)}
                        invalid={Boolean(errors[`allocations.${index}.amount`])}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="flex gap-2">
        <Button type="submit" loading={submitting}>
          {isEdit ? "Save draft" : "Create draft"}
        </Button>
        <Button type="button" variant="secondary" onClick={() => router.push("/customer-receipts")} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
