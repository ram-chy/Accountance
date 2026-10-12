"use client";

import { useState, type FormEvent } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { FieldError, FormField, Input } from "@/components/ui/field";
import { ApiError } from "@/lib/api/errors";
import { sumDecimalStrings } from "@/lib/dashboard/format";
import { todayDateOnly } from "@/lib/format/date";
import { MONEY_DISPLAY_DECIMALS, formatMoney } from "@/lib/format/money";
import { useAccountOptions } from "@/lib/accounts/use-account-options";
import { createJournal, updateJournal } from "@/lib/journals/api";
import type { Journal, JournalWritePayload } from "@/lib/journals/types";
import { validateJournalWrite } from "@/lib/journals/validators";

/**
 * Create / edit draft journal dialog with a line editor.
 *
 * Controlled inputs keep the user's work across a failed submit; the parent
 * remounts this component (via `key`) when switching between records or modes.
 * Each line is a one-sided entry: typing an amount on one side clears the other,
 * and the payload always sends both `debit` and `credit` (the empty side as
 * `"0"`) because the backend validates both as required decimals. Client
 * validation mirrors `ValidatesJournalLines`; the backend remains authoritative
 * and its 422 field errors are shown verbatim, including on the named line.
 */

type EditorMode = { mode: "create" } | { mode: "edit"; journal: Journal };

type JournalFormDialogProps = {
  editor: EditorMode | null;
  companyId: number | null;
  onClose: () => void;
  onSaved: () => Promise<void> | void;
};

type Editable = {
  account_id: number;
  description: string | null;
  debit: string;
  credit: string;
};

type LineDraft = {
  key: string;
  account_id: string;
  description: string;
  debit: string;
  credit: string;
};

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

/** Strip a decimal input down to digits and at most one dot. */
function sanitizeAmount(value: string): string {
  const cleaned = value.replace(/[^0-9.]/g, "");
  const [head, ...rest] = cleaned.split(".");
  return rest.length === 0 ? head : `${head}.${rest.join("")}`;
}

/** Hide a stored zero amount so an unused side shows empty, not `0.0000`. */
function displayAmount(value: string): string {
  return /^0+(?:\.0+)?$/.test(value.trim()) ? "" : value;
}

function newLine(key: string): LineDraft {
  return { key, account_id: "", description: "", debit: "", credit: "" };
}

/**
 * Line keys only need to be unique within a render, but a module counter keeps
 * them unique across remounts too. Keys are internal and never reach the DOM, so
 * this cannot cause an SSR/CSR hydration mismatch.
 */
let lineKeySeed = 0;
function makeLineKey(): string {
  lineKeySeed += 1;
  return `line-${lineKeySeed}`;
}

function lineToDraft(key: string, line: Editable): LineDraft {
  return {
    key,
    account_id: String(line.account_id),
    description: line.description ?? "",
    debit: displayAmount(line.debit),
    credit: displayAmount(line.credit),
  };
}

export function JournalFormDialog({
  editor,
  companyId,
  onClose,
  onSaved,
}: JournalFormDialogProps) {
  const open = editor !== null;
  const editing = editor?.mode === "edit" ? editor.journal : null;

  const accountsState = useAccountOptions(companyId, open);
  const accounts = accountsState.items;

  const [journalDate, setJournalDate] = useState(editing?.journal_date ?? todayDateOnly());
  const [description, setDescription] = useState(editing?.description ?? "");
  const [reference, setReference] = useState(editing?.reference ?? "");
  const [lines, setLines] = useState<LineDraft[]>(() =>
    editing
      ? editing.lines.map((line) => lineToDraft(makeLineKey(), line))
      : [newLine(makeLineKey()), newLine(makeLineKey())],
  );
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  const firstError = (key: string): string | undefined => fieldErrors[key]?.[0];

  const debitTotal = sumDecimalStrings(lines.map((line) => line.debit));
  const creditTotal = sumDecimalStrings(lines.map((line) => line.credit));
  const balanced = debitTotal === creditTotal;

  const updateLine = (key: string, patch: Partial<LineDraft>) => {
    setLines((current) =>
      current.map((line) => (line.key === key ? { ...line, ...patch } : line)),
    );
  };

  const addLine = () => {
    setLines((current) =>
      current.length >= 500 ? current : [...current, newLine(makeLineKey())],
    );
  };

  const removeLine = (key: string) => {
    setLines((current) =>
      current.length <= 2 ? current : current.filter((line) => line.key !== key),
    );
  };

  const toPayload = (): JournalWritePayload => ({
    journal_date: journalDate.trim(),
    description: description.trim() === "" ? null : description.trim(),
    reference: reference.trim() === "" ? null : reference.trim(),
    lines: lines.map((line) => ({
      account_id: Number(line.account_id),
      description: line.description.trim() === "" ? null : line.description.trim(),
      debit: line.debit.trim() === "" ? "0" : line.debit.trim(),
      credit: line.credit.trim() === "" ? "0" : line.credit.trim(),
    })),
  });

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFieldErrors({});
    setFormError(null);

    const payload = toPayload();
    const clientErrors = validateJournalWrite(payload);

    if (Object.keys(clientErrors).length > 0) {
      setFieldErrors(
        Object.fromEntries(
          Object.entries(clientErrors).map(([key, message]) => [key, [message]]),
        ),
      );
      setFormError("Please correct the highlighted lines.");
      return;
    }

    setPending(true);

    try {
      if (editing === null) {
        await createJournal(payload);
      } else {
        await updateJournal(editing.id, payload);
      }
      await onSaved();
      onClose();
    } catch (error) {
      if (error instanceof ApiError && error.isValidation && error.errors) {
        setFieldErrors(error.errors);
        setFormError("Please correct the highlighted lines.");
      } else if (error instanceof ApiError) {
        setFormError(error.message);
      } else {
        setFormError("Something went wrong. Please try again.");
      }
    } finally {
      setPending(false);
    }
  };

  const accountsUnavailable = accountsState.status === "error";

  return (
    <Dialog
      open={open}
      title={editing ? `Edit ${editing.journal_number}` : "New journal"}
      description={
        editing
          ? "Editing a draft replaces its lines with the ones below."
          : "A draft you can keep editing until you post it."
      }
      onClose={pending ? () => undefined : onClose}
      className="max-w-3xl"
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button
            type="submit"
            form="journal-form"
            loading={pending}
            disabled={accountsUnavailable}
          >
            {editing ? "Save changes" : "Create draft"}
          </Button>
        </>
      }
    >
      <form id="journal-form" onSubmit={handleSubmit} noValidate className="flex flex-col gap-5">
        {formError ? (
          <ErrorState title="Could not save the journal" message={formError} />
        ) : null}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField
            label="Date"
            htmlFor="journal-date"
            required
            error={firstError("journal_date")}
          >
            <Input
              id="journal-date"
              type="date"
              value={journalDate}
              onChange={(event) => setJournalDate(event.target.value)}
              invalid={Boolean(firstError("journal_date"))}
              aria-describedby={firstError("journal_date") ? "journal-date-error" : undefined}
            />
          </FormField>

          <FormField label="Reference" htmlFor="journal-reference" error={firstError("reference")}>
            <Input
              id="journal-reference"
              value={reference}
              onChange={(event) => setReference(event.target.value)}
              invalid={Boolean(firstError("reference"))}
              maxLength={255}
              autoComplete="off"
            />
          </FormField>

          <div className="sm:col-span-2">
            <FormField
              label="Description"
              htmlFor="journal-description"
              error={firstError("description")}
            >
              <Input
                id="journal-description"
                value={description}
                onChange={(event) => setDescription(event.target.value)}
                invalid={Boolean(firstError("description"))}
                maxLength={1000}
                autoComplete="off"
              />
            </FormField>
          </div>
        </div>

        <div className="flex flex-col gap-3">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-medium text-foreground">Lines</h3>
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={addLine}
              disabled={lines.length >= 500}
            >
              Add line
            </Button>
          </div>

          {accountsState.status === "loading" ? (
            <p className="text-sm text-muted-foreground">Loading accounts…</p>
          ) : null}

          {accountsUnavailable ? (
            <ErrorState title="Accounts unavailable" message={accountsState.message} />
          ) : null}

          <div className="flex flex-col gap-3">
            {lines.map((line, index) => {
              const accountError = firstError(`lines.${index}.account_id`);
              const debitError = firstError(`lines.${index}.debit`);
              const creditError = firstError(`lines.${index}.credit`);
              const amountError = firstError(`lines.${index}.amount`);

              return (
                <div
                  key={line.key}
                  className="flex flex-col gap-3 rounded-md border border-border p-3"
                >
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-medium text-muted-foreground">
                      Line {index + 1}
                    </span>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => removeLine(line.key)}
                      disabled={lines.length <= 2}
                    >
                      Remove
                    </Button>
                  </div>

                  <FormField
                    label="Account"
                    htmlFor={`line-${line.key}-account`}
                    required
                    error={accountError}
                  >
                    <select
                      id={`line-${line.key}-account`}
                      className={`${selectClasses} ${accountError ? "border-danger" : ""}`}
                      value={line.account_id}
                      onChange={(event) =>
                        updateLine(line.key, { account_id: event.target.value })
                      }
                      disabled={accountsUnavailable}
                      aria-invalid={Boolean(accountError)}
                    >
                      <option value="">Select an account</option>
                      {accounts.map((account) => (
                        <option key={account.id} value={account.id}>
                          {account.code} — {account.name}
                          {account.is_active ? "" : " (inactive)"}
                        </option>
                      ))}
                    </select>
                  </FormField>

                  <FormField label="Description" htmlFor={`line-${line.key}-description`}>
                    <Input
                      id={`line-${line.key}-description`}
                      value={line.description}
                      maxLength={1000}
                      autoComplete="off"
                      onChange={(event) =>
                        updateLine(line.key, { description: event.target.value })
                      }
                    />
                  </FormField>

                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <FormField
                      label="Debit"
                      htmlFor={`line-${line.key}-debit`}
                      error={debitError}
                    >
                      <Input
                        id={`line-${line.key}-debit`}
                        inputMode="decimal"
                        placeholder="0.00"
                        value={line.debit}
                        invalid={Boolean(debitError)}
                        onChange={(event) =>
                          updateLine(line.key, {
                            debit: sanitizeAmount(event.target.value),
                            credit: "",
                          })
                        }
                      />
                    </FormField>

                    <FormField
                      label="Credit"
                      htmlFor={`line-${line.key}-credit`}
                      error={creditError}
                    >
                      <Input
                        id={`line-${line.key}-credit`}
                        inputMode="decimal"
                        placeholder="0.00"
                        value={line.credit}
                        invalid={Boolean(creditError)}
                        onChange={(event) =>
                          updateLine(line.key, {
                            credit: sanitizeAmount(event.target.value),
                            debit: "",
                          })
                        }
                      />
                    </FormField>
                  </div>

                  {amountError ? (
                    <p className="text-xs font-medium text-danger">{amountError}</p>
                  ) : null}
                </div>
              );
            })}
          </div>

          <div className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-surface-muted px-3 py-2">
            <span className="text-xs font-medium text-muted-foreground">Totals</span>
            <div className="flex flex-wrap items-center gap-3 font-mono text-sm text-foreground">
              <span>Debit {formatMoney(debitTotal, { decimals: MONEY_DISPLAY_DECIMALS })}</span>
              <span>Credit {formatMoney(creditTotal, { decimals: MONEY_DISPLAY_DECIMALS })}</span>
              <Badge variant={balanced ? "success" : "warning"}>
                {balanced ? "Balanced" : "Out of balance"}
              </Badge>
            </div>
          </div>

          {firstError("lines") ? <FieldError role="alert">{firstError("lines")}</FieldError> : null}
        </div>
      </form>
    </Dialog>
  );
}
