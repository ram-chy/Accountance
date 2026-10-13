"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { useAccountOptions } from "@/lib/accounts/use-account-options";
import { ApiError, firstFieldErrors } from "@/lib/api/errors";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import { createPurchaseBill, updatePurchaseBill } from "@/lib/purchase-bills/api";
import { computeBillTotals, computeLineTotals } from "@/lib/purchase-bills/totals";
import type {
  PurchaseBill,
  PurchaseBillLinePayload,
  PurchaseBillWritePayload,
} from "@/lib/purchase-bills/types";
import { usePurchaseBill } from "@/lib/purchase-bills/use-purchase-bill";
import { parsePurchaseBillOrThrow, validatePurchaseBillWrite } from "@/lib/purchase-bills/validators";
import { useSupplierOptions } from "@/lib/suppliers/use-supplier-options";

const inputClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

const selectClasses = inputClasses;
const textareaClasses =
  "min-h-20 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled";

function emptyLine(): PurchaseBillLinePayload {
  return {
    description: "",
    quantity: "1",
    unit_cost: "",
    discount: "0",
    tax_rate: "0",
    expense_account_id: null,
  };
}

const EMPTY_FORM: PurchaseBillWritePayload = {
  supplier_id: null,
  bill_date: "",
  due_date: "",
  notes: null,
  tax_account_id: null,
  lines: [emptyLine()],
};

function orNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function seedForm(bill: PurchaseBill | null): PurchaseBillWritePayload {
  if (bill === null) {
    return EMPTY_FORM;
  }

  return {
    supplier_id: bill.supplier_id,
    bill_date: bill.bill_date,
    due_date: bill.due_date,
    notes: bill.notes,
    tax_account_id: bill.tax_account_id,
    lines:
      bill.lines.length > 0
        ? bill.lines.map((line) => ({
            description: line.description ?? "",
            quantity: line.quantity,
            unit_cost: line.unit_cost,
            discount: line.discount,
            tax_rate: line.tax_rate,
            expense_account_id: line.expense_account_id,
          }))
        : [emptyLine()],
  };
}

/**
 * Create/edit shell.
 *
 * The load / permission / status gating lives here; the editor below owns the
 * form state and is remounted with a `key` once the bill it edits is known, so
 * an edit never seeds from a stale record and no effect has to write state.
 */
export function PurchaseBillForm({ billId }: { billId?: number }) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const isEdit = billId !== undefined;
  const canCreate = can("purchases.bills.create");
  const canUpdate = can("purchases.bills.update");

  const detail = usePurchaseBill({
    billId: billId ?? 0,
    companyId,
    enabled: isEdit,
    reloadToken: 0,
  });

  if (!activeCompany) {
    return <EmptyState title="No company selected" description="Choose a company to manage purchase bills." />;
  }
  if (isEdit && !canUpdate) {
    return <EmptyState title="Bills unavailable" description="Your role does not include editing purchase bills." />;
  }
  if (!isEdit && !canCreate) {
    return <EmptyState title="Bills unavailable" description="Your role does not include creating purchase bills." />;
  }
  if (isEdit && detail.status === "loading") {
    return <LoadingState label="Loading bill…" />;
  }
  if (isEdit && detail.status === "notfound") {
    return <EmptyState title="Bill not found" description="This bill does not exist for the selected company." />;
  }
  if (isEdit && (detail.status === "error" || detail.status === "unavailable")) {
    return (
      <ErrorState
        message={detail.message}
        requestId={detail.status === "error" ? detail.requestId : undefined}
      />
    );
  }
  if (isEdit && detail.status === "ready" && detail.data.status !== "DRAFT") {
    return (
      <EmptyState
        title="Bill cannot be edited"
        description="Only draft bills can be edited. Posted bills are immutable."
      />
    );
  }

  return (
    <PurchaseBillEditor
      key={isEdit ? `edit-${billId}` : "new"}
      billId={billId}
      bill={isEdit && detail.status === "ready" ? detail.data : null}
      companyId={companyId}
      isEdit={isEdit}
    />
  );
}

type PurchaseBillEditorProps = {
  billId?: number;
  bill: PurchaseBill | null;
  companyId: number | null;
  isEdit: boolean;
};

function PurchaseBillEditor({ billId, bill, companyId, isEdit }: PurchaseBillEditorProps) {
  const router = useRouter();

  const accountsState = useAccountOptions(companyId, true);
  const suppliersState = useSupplierOptions(companyId, true);
  const accounts = accountsState.status === "ready" ? accountsState.items : [];
  const suppliers = suppliersState.status === "ready"
    ? suppliersState.items.filter((supplier) => supplier.is_active)
    : [];

  const [form, setForm] = useState<PurchaseBillWritePayload>(() => seedForm(bill));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const setLine = (index: number, patch: Partial<PurchaseBillLinePayload>) => {
    setForm((current) => ({
      ...current,
      lines: current.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
    }));
  };

  const addLine = () => setForm((current) => ({ ...current, lines: [...current.lines, emptyLine()] }));

  const removeLine = (index: number) =>
    setForm((current) => ({
      ...current,
      lines: current.lines.filter((_, i) => i !== index),
    }));

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;

    const payload: PurchaseBillWritePayload = {
      ...form,
      lines: form.lines.map((line) => ({
        ...line,
        quantity: line.quantity.trim(),
        unit_cost: line.unit_cost.trim(),
        discount: line.discount.trim() === "" ? "0" : line.discount.trim(),
        tax_rate: line.tax_rate.trim() === "" ? "0" : line.tax_rate.trim(),
      })),
    };

    const clientErrors = validatePurchaseBillWrite(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const raw = isEdit && billId
        ? await updatePurchaseBill(billId, payload)
        : await createPurchaseBill(payload);
      const saved = parsePurchaseBillOrThrow(raw);
      router.push(`/purchase-bills/${saved.id}`);
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError("We could not save this bill. Please try again.");
      }
      setSubmitting(false);
    }
  };

  const totals = computeBillTotals(form.lines);
  const money = (value: string) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  return (
    <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
      <div>
        <h1 className="text-2xl font-semibold text-foreground">
          {isEdit ? "Edit draft bill" : "New purchase bill"}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          The server recalculates all totals when you save. The summary below is an estimate.
        </p>
      </div>

      {formError ? (
        <p role="alert" className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground">
          {formError}
        </p>
      ) : null}

      <div className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2">
        <FormField label="Supplier" htmlFor="bill-supplier" required error={errors.supplier_id}>
          <select
            id="bill-supplier"
            className={selectClasses}
            value={form.supplier_id === null ? "" : String(form.supplier_id)}
            onChange={(event) =>
              setForm((current) => ({
                ...current,
                supplier_id: event.target.value === "" ? null : Number(event.target.value),
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

        <FormField label="Tax account" htmlFor="bill-tax-account" error={errors.tax_account_id} hint="Required once the bill charges tax.">
          <select
            id="bill-tax-account"
            className={selectClasses}
            value={form.tax_account_id === null ? "" : String(form.tax_account_id)}
            onChange={(event) =>
              setForm((current) => ({
                ...current,
                tax_account_id: event.target.value === "" ? null : Number(event.target.value),
              }))
            }
            disabled={accountsState.status !== "ready"}
          >
            <option value="">None</option>
            {accounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.code} — {account.name}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Bill date" htmlFor="bill-date" required error={errors.bill_date}>
          <Input
            id="bill-date"
            type="date"
            value={form.bill_date}
            onChange={(event) => setForm((current) => ({ ...current, bill_date: event.target.value }))}
            invalid={Boolean(errors.bill_date)}
          />
        </FormField>

        <FormField label="Due date" htmlFor="bill-due" required error={errors.due_date}>
          <Input
            id="bill-due"
            type="date"
            value={form.due_date}
            onChange={(event) => setForm((current) => ({ ...current, due_date: event.target.value }))}
            invalid={Boolean(errors.due_date)}
          />
        </FormField>

        <div className="sm:col-span-2">
          <FormField label="Notes" htmlFor="bill-notes" error={errors.notes}>
            <textarea
              id="bill-notes"
              className={textareaClasses}
              value={form.notes ?? ""}
              onChange={(event) => setForm((current) => ({ ...current, notes: orNull(event.target.value) }))}
            />
          </FormField>
        </div>
      </div>

      <div className="flex flex-col gap-3">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-medium">Lines</h2>
          <Button type="button" variant="secondary" size="sm" onClick={addLine}>
            Add line
          </Button>
        </div>
        {errors.lines ? <p className="text-sm text-danger">{errors.lines}</p> : null}

        <div className="flex flex-col gap-3">
          {form.lines.map((line, index) => {
            const lineTotals = computeLineTotals(line);
            const prefix = `lines.${index}.`;
            return (
              <div key={index} className="rounded-lg border border-border bg-surface p-3">
                <div className="grid gap-3 sm:grid-cols-12">
                  <div className="sm:col-span-4">
                    <FormField label="Description" htmlFor={`line-${index}-desc`} error={errors[`${prefix}description`]}>
                      <Input
                        id={`line-${index}-desc`}
                        value={line.description ?? ""}
                        onChange={(event) => setLine(index, { description: event.target.value })}
                      />
                    </FormField>
                  </div>
                  <div className="sm:col-span-2">
                    <FormField label="Qty" htmlFor={`line-${index}-qty`} error={errors[`${prefix}quantity`]}>
                      <Input
                        id={`line-${index}-qty`}
                        inputMode="decimal"
                        value={line.quantity}
                        onChange={(event) => setLine(index, { quantity: event.target.value })}
                        invalid={Boolean(errors[`${prefix}quantity`])}
                      />
                    </FormField>
                  </div>
                  <div className="sm:col-span-2">
                    <FormField label="Unit cost" htmlFor={`line-${index}-cost`} error={errors[`${prefix}unit_cost`]}>
                      <Input
                        id={`line-${index}-cost`}
                        inputMode="decimal"
                        value={line.unit_cost}
                        onChange={(event) => setLine(index, { unit_cost: event.target.value })}
                        invalid={Boolean(errors[`${prefix}unit_cost`])}
                      />
                    </FormField>
                  </div>
                  <div className="sm:col-span-2">
                    <FormField label="Discount" htmlFor={`line-${index}-discount`} error={errors[`${prefix}discount`]}>
                      <Input
                        id={`line-${index}-discount`}
                        inputMode="decimal"
                        value={line.discount}
                        onChange={(event) => setLine(index, { discount: event.target.value })}
                        invalid={Boolean(errors[`${prefix}discount`])}
                      />
                    </FormField>
                  </div>
                  <div className="sm:col-span-2">
                    <FormField label="Tax %" htmlFor={`line-${index}-tax`} error={errors[`${prefix}tax_rate`]}>
                      <Input
                        id={`line-${index}-tax`}
                        inputMode="decimal"
                        value={line.tax_rate}
                        onChange={(event) => setLine(index, { tax_rate: event.target.value })}
                        invalid={Boolean(errors[`${prefix}tax_rate`])}
                      />
                    </FormField>
                  </div>
                  <div className="sm:col-span-8">
                    <FormField
                      label="Expense account"
                      htmlFor={`line-${index}-account`}
                      required
                      error={errors[`${prefix}expense_account_id`]}
                    >
                      <select
                        id={`line-${index}-account`}
                        className={selectClasses}
                        value={line.expense_account_id === null ? "" : String(line.expense_account_id)}
                        onChange={(event) =>
                          setLine(index, {
                            expense_account_id: event.target.value === "" ? null : Number(event.target.value),
                          })
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
                  </div>
                  <div className="flex items-end justify-between gap-2 sm:col-span-4">
                    <div className="text-sm text-muted-foreground">
                      Line total: <span className="text-foreground">{money(lineTotals.lineTotal)}</span>
                    </div>
                    <Button
                      type="button"
                      variant="danger"
                      size="sm"
                      onClick={() => removeLine(index)}
                      disabled={form.lines.length === 1}
                    >
                      Remove
                    </Button>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      </div>

      <div className="grid gap-3 rounded-lg border border-border bg-surface p-4 sm:grid-cols-4">
        <div>
          <div className="text-xs uppercase text-muted-foreground">Subtotal</div>
          <div className="text-sm font-medium">{money(totals.subtotal)}</div>
        </div>
        <div>
          <div className="text-xs uppercase text-muted-foreground">Discount</div>
          <div className="text-sm font-medium">{money(totals.discountTotal)}</div>
        </div>
        <div>
          <div className="text-xs uppercase text-muted-foreground">Tax</div>
          <div className="text-sm font-medium">{money(totals.taxTotal)}</div>
        </div>
        <div>
          <div className="text-xs uppercase text-muted-foreground">Grand total (est.)</div>
          <div className="text-sm font-semibold">{money(totals.grandTotal)}</div>
        </div>
      </div>

      <div className="flex gap-2">
        <Button type="submit" loading={submitting}>
          {isEdit ? "Save draft" : "Create draft"}
        </Button>
        <Button type="button" variant="secondary" onClick={() => router.push("/purchase-bills")} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
