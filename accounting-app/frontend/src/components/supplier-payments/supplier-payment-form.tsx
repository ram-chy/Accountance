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
import { formatDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import { createSupplierPayment, updateSupplierPayment } from "@/lib/supplier-payments/api";
import { useOutstandingBills } from "@/lib/supplier-payments/use-outstanding-bills";
import { useSupplierPayment } from "@/lib/supplier-payments/use-supplier-payment";
import type {
  SupplierBillAllocationPayload,
  SupplierPayment,
  SupplierPaymentWritePayload,
} from "@/lib/supplier-payments/types";
import { parseSupplierPaymentOrThrow, validateSupplierPaymentWrite } from "@/lib/supplier-payments/validators";
import { useSupplierOptions } from "@/lib/suppliers/use-supplier-options";

const inputClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

const textareaClasses =
  "min-h-20 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled";

type BillOption = {
  id: number;
  bill_number: string;
  bill_date: string | null;
  due_date: string | null;
  grand_total: string | null;
  balance_due: string | null;
};

const EMPTY_FORM: SupplierPaymentWritePayload = {
  supplier_id: null,
  payment_date: "",
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

function seedForm(payment: SupplierPayment | null): SupplierPaymentWritePayload {
  if (payment === null) {
    return EMPTY_FORM;
  }

  return {
    supplier_id: payment.supplier_id,
    payment_date: payment.payment_date,
    amount: payment.amount,
    payment_account_id: payment.payment_account_id,
    reference: payment.reference,
    notes: payment.notes,
    allocations: payment.allocations.map((allocation) => ({
      purchase_bill_id: allocation.purchase_bill_id,
      amount: allocation.amount,
    })),
  };
}

/**
 * Create/edit shell.
 *
 * Load / permission / status gating lives here; the editor owns the form state
 * and is remounted with a `key` once the payment is known, so the allocation
 * editor seeds from the loaded draft without an effect writing state.
 */
export function SupplierPaymentForm({ paymentId }: { paymentId?: number }) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const isEdit = paymentId !== undefined;
  const canCreate = can("supplier.payments.create");
  const canUpdate = can("supplier.payments.update");

  const detail = useSupplierPayment({
    paymentId: paymentId ?? 0,
    companyId,
    enabled: isEdit,
    reloadToken: 0,
  });

  if (!activeCompany) {
    return <EmptyState title="No company selected" description="Choose a company to record payments." />;
  }
  if (isEdit && !canUpdate) {
    return <EmptyState title="Payments unavailable" description="Your role does not include editing supplier payments." />;
  }
  if (!isEdit && !canCreate) {
    return <EmptyState title="Payments unavailable" description="Your role does not include creating supplier payments." />;
  }
  if (isEdit && detail.status === "loading") {
    return <LoadingState label="Loading payment…" />;
  }
  if (isEdit && detail.status === "notfound") {
    return <EmptyState title="Payment not found" description="This payment does not exist for the selected company." />;
  }
  if (isEdit && (detail.status === "error" || detail.status === "unavailable")) {
    return <ErrorState message={detail.message} requestId={detail.status === "error" ? detail.requestId : undefined} />;
  }
  if (isEdit && detail.status === "ready" && detail.data.status !== "DRAFT") {
    return (
      <EmptyState
        title="Payment cannot be edited"
        description="Only draft payments can be edited. Posted payments are immutable."
      />
    );
  }

  return (
    <SupplierPaymentEditor
      key={isEdit ? `edit-${paymentId}` : "new"}
      paymentId={paymentId}
      payment={isEdit && detail.status === "ready" ? detail.data : null}
      companyId={companyId}
      isEdit={isEdit}
    />
  );
}

type SupplierPaymentEditorProps = {
  paymentId?: number;
  payment: SupplierPayment | null;
  companyId: number | null;
  isEdit: boolean;
};

function SupplierPaymentEditor({ paymentId, payment, companyId, isEdit }: SupplierPaymentEditorProps) {
  const router = useRouter();

  const suppliersState = useSupplierOptions(companyId, true);
  const suppliers = suppliersState.status === "ready"
    ? suppliersState.items.filter((supplier) => supplier.is_active)
    : [];
  const accountsState = useCashBankAccountOptions(companyId, true);
  const accounts = accountsState.status === "ready" ? accountsState.items : [];

  const [form, setForm] = useState<SupplierPaymentWritePayload>(() => seedForm(payment));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const outstanding = useOutstandingBills(companyId, form.supplier_id, true);

  const bills = useMemo<BillOption[]>(() => {
    const merged = new Map<number, BillOption>();

    if (outstanding.status === "ready") {
      for (const bill of outstanding.items) {
        merged.set(bill.id, {
          id: bill.id,
          bill_number: bill.bill_number,
          bill_date: bill.bill_date,
          due_date: bill.due_date,
          grand_total: bill.grand_total,
          balance_due: bill.balance_due ?? null,
        });
      }
    }

    if (isEdit && payment) {
      for (const allocation of payment.allocations) {
        const ref = allocation.purchase_bill;
        if (ref && !merged.has(ref.id)) {
          merged.set(ref.id, {
            id: ref.id,
            bill_number: ref.bill_number,
            bill_date: null,
            due_date: null,
            grand_total: ref.grand_total ?? null,
            balance_due: ref.balance_due ?? null,
          });
        } else if (!ref && !merged.has(allocation.purchase_bill_id)) {
          merged.set(allocation.purchase_bill_id, {
            id: allocation.purchase_bill_id,
            bill_number: `#${allocation.purchase_bill_id}`,
            bill_date: null,
            due_date: null,
            grand_total: null,
            balance_due: null,
          });
        }
      }
    }

    return Array.from(merged.values()).sort((a, b) => a.bill_number.localeCompare(b.bill_number));
  }, [outstanding, isEdit, payment]);

  const allocationAmount = (billId: number): string =>
    form.allocations.find((allocation) => allocation.purchase_bill_id === billId)?.amount ?? "";

  const setAllocation = (billId: number, value: string) => {
    setForm((current) => {
      const others = current.allocations.filter((allocation) => allocation.purchase_bill_id !== billId);
      const trimmed = value.trim();
      const allocations: SupplierBillAllocationPayload[] =
        trimmed === ""
          ? others
          : [...others, { purchase_bill_id: billId, amount: trimmed }];
      return { ...current, allocations };
    });
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;

    const payload: SupplierPaymentWritePayload = {
      ...form,
      amount: form.amount.trim(),
      allocations: form.allocations.map((allocation) => ({
        purchase_bill_id: allocation.purchase_bill_id,
        amount: allocation.amount.trim(),
      })),
    };

    const clientErrors = validateSupplierPaymentWrite(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const raw = isEdit && paymentId
        ? await updateSupplierPayment(paymentId, payload)
        : await createSupplierPayment(payload);
      const saved = parseSupplierPaymentOrThrow(raw);
      router.push(`/supplier-payments/${saved.id}`);
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this payment. Please try again.");
      }
      setSubmitting(false);
    }
  };

  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  return (
    <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
      <div>
        <h1 className="text-2xl font-semibold text-foreground">
          {isEdit ? "Edit draft payment" : "New supplier payment"}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Allocate the full payment amount across the supplier&apos;s outstanding bills.
        </p>
      </div>

      {formError ? (
        <p role="alert" className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground">
          {formError}
        </p>
      ) : null}

      <div className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2">
        <FormField label="Supplier" htmlFor="payment-supplier" required error={errors.supplier_id}>
          <select
            id="payment-supplier"
            className={inputClasses}
            value={form.supplier_id === null ? "" : String(form.supplier_id)}
            onChange={(event) =>
              setForm((current) => ({
                ...current,
                supplier_id: event.target.value === "" ? null : Number(event.target.value),
                allocations: [],
              }))
            }
            disabled={suppliersState.status !== "ready"}
          >
            <option value="">Select a supplier…</option>
            {suppliers.map((supplier) => (
              <option key={supplier.id} value={supplier.id}>
                {supplier.supplier_code} — {supplier.name}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Payment account" htmlFor="payment-account" required error={errors.payment_account_id} hint="A cash or bank account.">
          <select
            id="payment-account"
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

        <FormField label="Payment date" htmlFor="payment-date" required error={errors.payment_date}>
          <Input
            id="payment-date"
            type="date"
            value={form.payment_date}
            onChange={(event) => setForm((current) => ({ ...current, payment_date: event.target.value }))}
            invalid={Boolean(errors.payment_date)}
          />
        </FormField>

        <FormField label="Amount paid" htmlFor="payment-amount" required error={errors.amount}>
          <Input
            id="payment-amount"
            inputMode="decimal"
            value={form.amount}
            onChange={(event) => setForm((current) => ({ ...current, amount: event.target.value }))}
            invalid={Boolean(errors.amount)}
          />
        </FormField>

        <FormField label="Reference" htmlFor="payment-reference" error={errors.reference}>
          <Input
            id="payment-reference"
            value={form.reference ?? ""}
            onChange={(event) => setForm((current) => ({ ...current, reference: orNull(event.target.value) }))}
          />
        </FormField>

        <div className="sm:col-span-2">
          <FormField label="Notes" htmlFor="payment-notes" error={errors.notes}>
            <textarea
              id="payment-notes"
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

        {form.supplier_id === null ? (
          <EmptyState title="Select a supplier" description="Outstanding bills appear once a supplier is chosen." />
        ) : outstanding.status === "error" ? (
          <ErrorState message={outstanding.message ?? "Could not load outstanding bills."} />
        ) : outstanding.status === "loading" ? (
          <LoadingState label="Loading outstanding bills…" />
        ) : bills.length === 0 ? (
          <EmptyState title="No outstanding bills" description="This supplier has no posted bills with a balance due." />
        ) : (
          <div className="overflow-x-auto rounded-lg border border-border bg-surface">
            <table className="min-w-full divide-y divide-border text-sm">
              <thead className="bg-surface-muted">
                <tr>
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Bill</th>
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
                  <th className="px-3 py-2 text-left font-medium text-muted-foreground">Due</th>
                  <th className="px-3 py-2 text-right font-medium text-muted-foreground">Total</th>
                  <th className="px-3 py-2 text-right font-medium text-muted-foreground">Outstanding</th>
                  <th className="px-3 py-2 text-right font-medium text-muted-foreground">Allocate</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {bills.map((bill, index) => (
                  <tr key={bill.id}>
                    <td className="px-3 py-2 font-mono text-xs">{bill.bill_number}</td>
                    <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(bill.bill_date)}</td>
                    <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(bill.due_date)}</td>
                    <td className="px-3 py-2 text-right">{money(bill.grand_total)}</td>
                    <td className="px-3 py-2 text-right">{money(bill.balance_due)}</td>
                    <td className="px-3 py-2">
                      <Input
                        aria-label={`Amount to allocate to ${bill.bill_number}`}
                        inputMode="decimal"
                        value={allocationAmount(bill.id)}
                        onChange={(event) => setAllocation(bill.id, event.target.value)}
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
        <Button type="button" variant="secondary" onClick={() => router.push("/supplier-payments")} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
