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
import { createCreditDebitNote, updateCreditDebitNote } from "@/lib/credit-debit-notes/api";
import { computeNoteTotals } from "@/lib/credit-debit-notes/totals";
import type {
  CreditDebitNote,
  CreditDebitNoteLinePayload,
  CreditDebitNoteWritePayload,
  NoteType,
} from "@/lib/credit-debit-notes/types";
import { useAdjustableLines } from "@/lib/credit-debit-notes/use-adjustable-lines";
import { useCreditDebitNote } from "@/lib/credit-debit-notes/use-credit-debit-note";
import { useNoteSourceDocuments } from "@/lib/credit-debit-notes/use-note-source-documents";
import { parseCreditDebitNoteOrThrow, validateCreditDebitNoteWrite } from "@/lib/credit-debit-notes/validators";
import { todayDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";

const inputClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

const textareaClasses =
  "min-h-20 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 " +
  "focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-disabled";

function emptyLine(): CreditDebitNoteLinePayload {
  return {
    description: "",
    quantity: "1",
    unit_price: "",
    discount: "0",
    tax_rate: "0",
    account_id: null,
    source_line_id: null,
  };
}

function orNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function emptyForm(noteType: NoteType): CreditDebitNoteWritePayload {
  return {
    note_type: noteType,
    source_id: null,
    note_date: todayDateOnly(),
    reason: "",
    reference: null,
    notes: null,
    tax_account_id: null,
    lines: [emptyLine()],
  };
}

function seedForm(noteType: NoteType, note: CreditDebitNote | null): CreditDebitNoteWritePayload {
  if (note === null) {
    return emptyForm(noteType);
  }

  return {
    note_type: note.note_type,
    source_id: note.source_document_id,
    note_date: note.note_date,
    reason: note.reason,
    reference: note.reference,
    notes: note.notes,
    tax_account_id: note.tax_account_id,
    lines:
      note.lines.length > 0
        ? note.lines.map((line) => ({
            description: line.description ?? "",
            quantity: line.quantity,
            unit_price: line.unit_price,
            discount: line.discount,
            tax_rate: line.tax_rate,
            account_id: line.account_id,
            source_line_id: line.source_line_id,
          }))
        : [emptyLine()],
  };
}

/** Human labels that vary by note family. */
function familyLabels(noteType: NoteType) {
  const sales = noteType === "SALES_CREDIT_NOTE" || noteType === "SALES_DEBIT_NOTE";
  const credit = noteType === "SALES_CREDIT_NOTE" || noteType === "PURCHASE_CREDIT_NOTE";
  return {
    sales,
    credit,
    noun: `${sales ? "credit" : "debit"} note`,
    Noun: `${sales ? "Credit" : "Debit"} note`,
    sourceNoun: sales ? "invoice" : "bill",
    SourceNoun: sales ? "Invoice" : "Bill",
    accountLabel: sales ? "Revenue account" : "Expense account",
  };
}

export function CreditDebitNoteForm({
  noteType,
  basePath,
  noteId,
}: {
  noteType: NoteType;
  basePath: string;
  noteId?: number;
}) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const isEdit = noteId !== undefined;
  const labels = familyLabels(noteType);
  const canCreate = can("accounting.credit_debit_note.create");
  const canUpdate = can("accounting.credit_debit_note.update");

  const detail = useCreditDebitNote({
    noteId: noteId ?? 0,
    companyId,
    enabled: isEdit,
    reloadToken: 0,
  });

  if (!activeCompany) {
    return <EmptyState title="No company selected" description={`Choose a company to manage ${labels.noun}s.`} />;
  }
  if (isEdit && !canUpdate) {
    return <EmptyState title="Notes unavailable" description={`Your role does not include editing ${labels.noun}s.`} />;
  }
  if (!isEdit && !canCreate) {
    return <EmptyState title="Notes unavailable" description={`Your role does not include creating ${labels.noun}s.`} />;
  }
  if (isEdit && detail.status === "loading") {
    return <LoadingState label={`Loading ${labels.noun}…`} />;
  }
  if (isEdit && detail.status === "notfound") {
    return <EmptyState title="Note not found" description={`This ${labels.noun} does not exist for the selected company.`} />;
  }
  if (isEdit && (detail.status === "error" || detail.status === "unavailable")) {
    return (
      <ErrorState
        message={detail.message}
        requestId={detail.status === "error" ? detail.requestId : undefined}
      />
    );
  }
  if (isEdit && detail.status === "ready" && detail.data.note_type !== noteType) {
    return (
      <EmptyState
        title="Note not found"
        description={`This record is not a ${labels.noun}.`}
      />
    );
  }
  if (isEdit && detail.status === "ready" && detail.data.status !== "DRAFT") {
    return (
      <EmptyState
        title="Note cannot be edited"
        description={`Only draft ${labels.noun}s can be edited. Posted notes are immutable.`}
      />
    );
  }

  return (
    <CreditDebitNoteEditor
      key={isEdit ? `edit-${noteId}` : "new"}
      noteType={noteType}
      basePath={basePath}
      noteId={noteId}
      note={isEdit && detail.status === "ready" ? detail.data : null}
      companyId={companyId}
      isEdit={isEdit}
    />
  );
}

type EditorProps = {
  noteType: NoteType;
  basePath: string;
  noteId?: number;
  note: CreditDebitNote | null;
  companyId: number | null;
  isEdit: boolean;
};

function CreditDebitNoteEditor({ noteType, basePath, noteId, note, companyId, isEdit }: EditorProps) {
  const router = useRouter();
  const labels = familyLabels(noteType);

  const accountsState = useAccountOptions(companyId, true);
  const accounts = accountsState.status === "ready" ? accountsState.items : [];

  const sourcesState = useNoteSourceDocuments(companyId, noteType, true);

  const [form, setForm] = useState<CreditDebitNoteWritePayload>(() => seedForm(noteType, note));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const adjustable = useAdjustableLines({
    companyId,
    noteType,
    sourceId: form.source_id,
    enabled: true,
  });

  const setLine = (index: number, patch: Partial<CreditDebitNoteLinePayload>) => {
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

  const onSourceChange = (value: string) => {
    setForm((current) => ({
      ...current,
      source_id: value === "" ? null : Number(value),
      lines: [emptyLine()],
    }));
  };

  const onPickSourceLine = (index: number, value: string) => {
    if (value === "") {
      setLine(index, { source_line_id: null });
      return;
    }
    const sourceLine = adjustable.status === "ready"
      ? adjustable.data.lines.find((line) => line.source_line_id === Number(value))
      : undefined;
    if (!sourceLine) {
      return;
    }
    setLine(index, {
      source_line_id: sourceLine.source_line_id,
      description: sourceLine.description ?? "",
      quantity: sourceLine.remaining_quantity || sourceLine.quantity,
      unit_price: sourceLine.unit_price,
      tax_rate: sourceLine.tax_rate,
    });
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;

    const payload: CreditDebitNoteWritePayload = {
      ...form,
      reason: form.reason.trim(),
      lines: form.lines.map((line) => ({
        ...line,
        quantity: line.quantity.trim(),
        unit_price: line.unit_price.trim(),
        discount: line.discount.trim() === "" ? "0" : line.discount.trim(),
        tax_rate: line.tax_rate.trim() === "" ? "0" : line.tax_rate.trim(),
      })),
    };

    const clientErrors = validateCreditDebitNoteWrite(payload);
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const raw = isEdit && noteId
        ? await updateCreditDebitNote(noteId, payload)
        : await createCreditDebitNote(payload);
      const saved = parseCreditDebitNoteOrThrow(raw, labels.noun);
      router.push(`${basePath}/${saved.id}`);
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(firstFieldErrors(error.errors));
        setFormError(error.message);
      } else {
        setFormError(`We could not save this ${labels.noun}. Please try again.`);
      }
      setSubmitting(false);
    }
  };

  const totals = computeNoteTotals(form.lines);
  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  const remaining = adjustable.status === "ready" ? adjustable.data.remaining_adjustable_amount : null;
  const exceedsRemaining =
    remaining !== null && compareAmounts(totals.grandTotal, remaining) > 0;

  const adjustableLines = adjustable.status === "ready" ? adjustable.data.lines : [];

  return (
    <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
      <div>
        <h1 className="text-2xl font-semibold text-foreground">
          {isEdit ? `Edit draft ${labels.noun}` : `New ${labels.noun}`}
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
        <FormField
          label={`${labels.SourceNoun} being adjusted`}
          htmlFor="note-source"
          required
          error={errors.source_id}
          hint={isEdit ? "The source document cannot be changed after the note is created." : undefined}
        >
          <select
            id="note-source"
            className={inputClasses}
            value={form.source_id === null ? "" : String(form.source_id)}
            onChange={(event) => onSourceChange(event.target.value)}
            disabled={isEdit || sourcesState.status !== "ready"}
          >
            <option value="">Select a posted {labels.sourceNoun}…</option>
            {sourceOptions(sourcesState.items, form, isEdit).map((option) => (
              <option key={option.id} value={option.id}>
                {option.number}
                {option.counterparty ? ` — ${option.counterparty}` : ""}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Tax account" htmlFor="note-tax-account" error={errors.tax_account_id} hint="Required once the note charges tax.">
          <select
            id="note-tax-account"
            className={inputClasses}
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

        <FormField label="Note date" htmlFor="note-date" required error={errors.note_date}>
          <Input
            id="note-date"
            type="date"
            value={form.note_date}
            onChange={(event) => setForm((current) => ({ ...current, note_date: event.target.value }))}
            invalid={Boolean(errors.note_date)}
          />
        </FormField>

        <FormField label="Reference" htmlFor="note-reference" error={errors.reference} hint="Optional external reference.">
          <Input
            id="note-reference"
            value={form.reference ?? ""}
            onChange={(event) => setForm((current) => ({ ...current, reference: orNull(event.target.value) }))}
          />
        </FormField>

        <div className="sm:col-span-2">
          <FormField label="Reason" htmlFor="note-reason" required error={errors.reason}>
            <textarea
              id="note-reason"
              className={textareaClasses}
              value={form.reason}
              onChange={(event) => setForm((current) => ({ ...current, reason: event.target.value }))}
            />
          </FormField>
        </div>

        <div className="sm:col-span-2">
          <FormField label="Notes" htmlFor="note-notes" error={errors.notes}>
            <textarea
              id="note-notes"
              className={textareaClasses}
              value={form.notes ?? ""}
              onChange={(event) => setForm((current) => ({ ...current, notes: orNull(event.target.value) }))}
            />
          </FormField>
        </div>
      </div>

      {form.source_id !== null ? (
        <SourceAdjustmentPanel
          state={adjustable}
          money={money}
        />
      ) : null}

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
            const prefix = `lines.${index}.`;
            return (
              <div key={index} className="rounded-lg border border-border bg-surface p-3">
                <div className="grid gap-3 sm:grid-cols-12">
                  {adjustableLines.length > 0 ? (
                    <div className="sm:col-span-12">
                      <FormField
                        label={`Adjust ${labels.sourceNoun} line`}
                        htmlFor={`line-${index}-source`}
                        hint="Optional. Picking a source line prefills the figures still available to adjust."
                        error={errors[`${prefix}source_line_id`]}
                      >
                        <select
                          id={`line-${index}-source`}
                          className={inputClasses}
                          value={line.source_line_id === null ? "" : String(line.source_line_id)}
                          onChange={(event) => onPickSourceLine(index, event.target.value)}
                        >
                          <option value="">No specific line (document-level)</option>
                          {adjustableLines.map((source) => (
                            <option
                              key={source.source_line_id}
                              value={source.source_line_id}
                              disabled={!source.is_adjustable}
                            >
                              {`#${source.line_number}`}
                              {source.description ? ` — ${source.description}` : ""}
                              {` (remaining ${source.remaining_quantity})`}
                            </option>
                          ))}
                        </select>
                      </FormField>
                    </div>
                  ) : null}

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
                    <FormField label="Unit price" htmlFor={`line-${index}-price`} error={errors[`${prefix}unit_price`]}>
                      <Input
                        id={`line-${index}-price`}
                        inputMode="decimal"
                        value={line.unit_price}
                        onChange={(event) => setLine(index, { unit_price: event.target.value })}
                        invalid={Boolean(errors[`${prefix}unit_price`])}
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
                      label={labels.accountLabel}
                      htmlFor={`line-${index}-account`}
                      required
                      error={errors[`${prefix}account_id`]}
                    >
                      <select
                        id={`line-${index}-account`}
                        className={inputClasses}
                        value={line.account_id === null ? "" : String(line.account_id)}
                        onChange={(event) =>
                          setLine(index, {
                            account_id: event.target.value === "" ? null : Number(event.target.value),
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
                  <div className="flex items-end justify-end gap-2 sm:col-span-4">
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

      {exceedsRemaining && remaining !== null ? (
        <p
          role="status"
          className="rounded-md border border-warning-border bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground"
        >
          This note&apos;s estimated total ({money(totals.grandTotal)}) is more than the{" "}
          {money(remaining)} still adjustable on the {labels.sourceNoun}. The server will reject the save.
        </p>
      ) : null}

      <div className="flex gap-2">
        <Button type="submit" loading={submitting}>
          {isEdit ? "Save draft" : "Create draft"}
        </Button>
        <Button type="button" variant="secondary" onClick={() => router.push(basePath)} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

/**
 * A loaded source is not necessarily in the (capped) posted list, so on edit we
 * add the note's own source as an option if it is missing.
 */
function sourceOptions(
  items: { id: number; number: string; counterparty: string | null }[],
  form: CreditDebitNoteWritePayload,
  isEdit: boolean,
): { id: number; number: string; counterparty: string | null }[] {
  if (!isEdit || form.source_id === null || items.some((item) => item.id === form.source_id)) {
    return items;
  }
  return [{ id: form.source_id, number: `#${form.source_id}`, counterparty: null }, ...items];
}

function SourceAdjustmentPanel({
  state,
  money,
}: {
  state: ReturnType<typeof useAdjustableLines>;
  money: (value: string | null | undefined) => string;
}) {
  if (state.status === "loading" || state.status === "idle") {
    return <LoadingState label="Loading the adjustable amounts…" />;
  }
  if (state.status === "error") {
    return <ErrorState message={state.message} />;
  }

  const data = state.data;

  return (
    <div className="rounded-lg border border-border bg-surface p-4">
      <h2 className="text-sm font-medium text-foreground">
        Adjustable on {data.document_number ?? `#${data.source_id}`}
      </h2>
      <div className="mt-2 grid gap-3 sm:grid-cols-3">
        <div>
          <div className="text-xs uppercase text-muted-foreground">Document total</div>
          <div className="text-sm font-medium">{money(data.grand_total)}</div>
        </div>
        <div>
          <div className="text-xs uppercase text-muted-foreground">Already adjusted</div>
          <div className="text-sm font-medium">{money(data.net_adjustment)}</div>
        </div>
        <div>
          <div className="text-xs uppercase text-muted-foreground">Still adjustable</div>
          <div className="text-sm font-semibold">{money(data.remaining_adjustable_amount)}</div>
        </div>
      </div>
    </div>
  );
}

/**
 * Compare two unsigned decimal strings without floating point so the advisory
 * does not round the two figures it is comparing.
 */
function compareAmounts(a: string, b: string): number {
  const split = (value: string) => {
    const [int, frac = ""] = value.trim().split(".");
    return { int: int || "0", frac };
  };
  const sa = split(a);
  const sb = split(b);
  const scale = Math.max(sa.frac.length, sb.frac.length);
  const va = BigInt(sa.int + sa.frac.padEnd(scale, "0"));
  const vb = BigInt(sb.int + sb.frac.padEnd(scale, "0"));
  return va === vb ? 0 : va < vb ? -1 : 1;
}
