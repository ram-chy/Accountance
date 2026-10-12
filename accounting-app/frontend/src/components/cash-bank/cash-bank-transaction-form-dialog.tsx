"use client";

import { useState, type FormEvent } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { ApiError } from "@/lib/api/errors";
import { useAccountOptions } from "@/lib/accounts/use-account-options";
import { useCashBankAccountOptions } from "@/lib/cash-bank/use-cash-bank-accounts";
import { createCashBankTransaction, updateCashBankTransaction } from "@/lib/cash-bank/api";
import {
  CASH_BANK_TRANSACTION_TYPE_VALUES,
  type CashBankTransaction,
  type CashBankTransactionTypeValue,
  type CashBankTransactionWritePayload,
  cashBankTransactionTypeLabel,
} from "@/lib/cash-bank/types";
import { validateCashBankTransactionWrite } from "@/lib/cash-bank/validators";
import { todayDateOnly } from "@/lib/format/date";

/**
 * Create / edit draft cash/bank transaction dialog.
 *
 * The three movement types share one form because their field set is identical;
 * the difference is only which side must be a cash/bank account:
 *
 * - deposit: into cash/bank, from any account
 * - withdrawal: from cash/bank, into any account
 * - transfer: from and into cash/bank
 *
 * The backend makes the type a property of the route rather than the payload, so
 * it is chosen here and never sent. On edit the type is fixed and shown as
 * read-only. A posted transaction is final, so the parent only opens this dialog
 * for a draft.
 */

export type CashBankTransactionEditor =
  | { mode: "create" }
  | { mode: "edit"; transaction: CashBankTransaction };

type PickerOption = { id: number; code: string; name: string; is_active: boolean };

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

const textareaClasses =
  "min-h-20 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-foreground " +
  "placeholder:text-muted-foreground transition-colors " +
  "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

/** Strip an amount input down to digits and at most one dot. */
function sanitizeAmount(value: string): string {
  const cleaned = value.replace(/[^0-9.]/g, "");
  const [head, ...rest] = cleaned.split(".");
  return rest.length === 0 ? head : `${head}.${rest.join("")}`;
}

function typeHint(type: CashBankTransactionTypeValue | ""): string {
  switch (type) {
    case "DEPOSIT":
      return "Money received into a cash or bank account; the source can be any account.";
    case "WITHDRAWAL":
      return "Money paid out of a cash or bank account; the destination can be any account.";
    case "TRANSFER":
      return "Money moved between a cash/bank account and another cash/bank account.";
    default:
      return "Choose a type to pick the eligible accounts.";
  }
}

export function CashBankTransactionFormDialog({
  editor,
  companyId,
  onClose,
  onSaved,
}: {
  editor: CashBankTransactionEditor | null;
  companyId: number | null;
  onClose: () => void;
  onSaved: () => Promise<void> | void;
}) {
  const open = editor !== null;
  const editing = editor?.mode === "edit" ? editor.transaction : null;

  const accountsState = useAccountOptions(companyId, open);
  const cashBankState = useCashBankAccountOptions(companyId, open);

  const [type, setType] = useState<CashBankTransactionTypeValue | "">(
    editing ? editing.transaction_type : "",
  );
  const [date, setDate] = useState(editing?.transaction_date ?? todayDateOnly());
  const [amount, setAmount] = useState(editing?.amount ?? "");
  const [sourceId, setSourceId] = useState(editing ? String(editing.source_account_id) : "");
  const [destinationId, setDestinationId] = useState(
    editing ? String(editing.destination_account_id) : "",
  );
  const [reference, setReference] = useState(editing?.reference ?? "");
  const [notes, setNotes] = useState(editing?.notes ?? "");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  const firstError = (key: string): string | undefined => fieldErrors[key]?.[0];

  const typeValue: CashBankTransactionTypeValue | "" = editing ? editing.transaction_type : type;
  const typeChosen = typeValue !== "";
  const sourceIsCashBank = typeValue === "WITHDRAWAL" || typeValue === "TRANSFER";
  const destinationIsCashBank = typeValue === "DEPOSIT" || typeValue === "TRANSFER";

  const accountItems = accountsState.items as PickerOption[];
  const cashBankItems = cashBankState.items as PickerOption[];

  const sourceOptions = sourceIsCashBank ? cashBankItems : accountItems;
  const destinationOptions = destinationIsCashBank ? cashBankItems : accountItems;

  const accountsLoading =
    typeChosen &&
    (accountsState.status === "loading" || cashBankState.status === "loading");
  const accountsError =
    typeChosen && (accountsState.status === "error" || cashBankState.status === "error");
  const accountsErrorMessage =
    accountsState.status === "error"
      ? accountsState.message
      : cashBankState.status === "error"
        ? cashBankState.message
        : undefined;

  const toPayload = (): CashBankTransactionWritePayload => ({
    transaction_date: date.trim(),
    amount: amount.trim(),
    source_account_id: Number(sourceId),
    destination_account_id: Number(destinationId),
    reference: reference.trim() === "" ? null : reference.trim(),
    notes: notes.trim() === "" ? null : notes.trim(),
  });

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFieldErrors({});
    setFormError(null);

    const clientErrors: Record<string, string> =
      typeValue === ""
        ? { transaction_type: "Choose a transaction type." }
        : validateCashBankTransactionWrite(toPayload());

    if (Object.keys(clientErrors).length > 0) {
      setFieldErrors(
        Object.fromEntries(Object.entries(clientErrors).map(([key, message]) => [key, [message]])),
      );
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setPending(true);

    try {
      const payload = toPayload();

      if (editing === null) {
        await createCashBankTransaction(typeValue as CashBankTransactionTypeValue, payload);
      } else {
        await updateCashBankTransaction(editing.id, payload);
      }

      await onSaved();
      onClose();
    } catch (error) {
      if (error instanceof ApiError && error.isValidation && error.errors) {
        setFieldErrors(error.errors);
        setFormError(error.message);
      } else if (error instanceof ApiError) {
        setFormError(error.message);
      } else {
        setFormError("Something went wrong. Please try again.");
      }
    } finally {
      setPending(false);
    }
  };

  const title = editing
    ? `Edit ${editing.transaction_number}`
    : typeValue === ""
      ? "New cash/bank transaction"
      : `New ${cashBankTransactionTypeLabel(typeValue).toLowerCase()}`;

  return (
    <Dialog
      open={open}
      title={title}
      description={
        editing
          ? "Editing a draft replaces its details. A posted transaction is final."
          : "A draft you can keep editing until you post it."
      }
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button
            type="submit"
            form="cash-bank-transaction-form"
            loading={pending}
            disabled={accountsError}
          >
            {editing ? "Save changes" : "Create draft"}
          </Button>
        </>
      }
    >
      <form
        id="cash-bank-transaction-form"
        onSubmit={handleSubmit}
        noValidate
        className="flex flex-col gap-5"
      >
        {formError ? (
          <ErrorState title="Could not save the transaction" message={formError} />
        ) : null}

        <FormField
          label="Type"
          htmlFor="cash-bank-transaction-type"
          required={editing === null}
          hint={editing ? "A transaction's type is fixed once it is created." : typeHint(typeValue)}
          error={firstError("transaction_type")}
        >
          <select
            id="cash-bank-transaction-type"
            className={`${selectClasses} ${firstError("transaction_type") ? "border-danger" : ""}`}
            value={typeValue}
            onChange={(event) => {
              setType(event.target.value as CashBankTransactionTypeValue | "");
              setSourceId("");
              setDestinationId("");
            }}
            disabled={editing !== null}
            aria-invalid={Boolean(firstError("transaction_type"))}
          >
            <option value="">Select a type</option>
            {CASH_BANK_TRANSACTION_TYPE_VALUES.map((value) => (
              <option key={value} value={value}>
                {cashBankTransactionTypeLabel(value)}
              </option>
            ))}
          </select>
        </FormField>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField
            label="Date"
            htmlFor="cash-bank-transaction-date"
            required
            error={firstError("transaction_date")}
          >
            <Input
              id="cash-bank-transaction-date"
              type="date"
              value={date}
              onChange={(event) => setDate(event.target.value)}
              invalid={Boolean(firstError("transaction_date"))}
            />
          </FormField>

          <FormField
            label="Amount"
            htmlFor="cash-bank-transaction-amount"
            required
            error={firstError("amount")}
          >
            <Input
              id="cash-bank-transaction-amount"
              inputMode="decimal"
              placeholder="0.00"
              value={amount}
              invalid={Boolean(firstError("amount"))}
              onChange={(event) => setAmount(sanitizeAmount(event.target.value))}
            />
          </FormField>
        </div>

        {accountsLoading ? (
          <p className="text-sm text-muted-foreground">Loading accounts…</p>
        ) : null}

        {accountsError ? (
          <ErrorState title="Accounts unavailable" message={accountsErrorMessage} />
        ) : null}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField
            label={sourceIsCashBank ? "From cash/bank account" : "From account"}
            htmlFor="cash-bank-transaction-source"
            required
            error={firstError("source_account_id")}
          >
            <select
              id="cash-bank-transaction-source"
              className={`${selectClasses} ${firstError("source_account_id") ? "border-danger" : ""}`}
              value={sourceId}
              onChange={(event) => setSourceId(event.target.value)}
              disabled={!typeChosen || accountsError || accountsLoading}
              aria-invalid={Boolean(firstError("source_account_id"))}
            >
              <option value="">Select an account</option>
              {sourceOptions.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.code} — {account.name}
                  {account.is_active ? "" : " (inactive)"}
                </option>
              ))}
            </select>
          </FormField>

          <FormField
            label={destinationIsCashBank ? "Into cash/bank account" : "Into account"}
            htmlFor="cash-bank-transaction-destination"
            required
            error={firstError("destination_account_id")}
          >
            <select
              id="cash-bank-transaction-destination"
              className={`${selectClasses} ${firstError("destination_account_id") ? "border-danger" : ""}`}
              value={destinationId}
              onChange={(event) => setDestinationId(event.target.value)}
              disabled={typeValue === "" || accountsError || accountsLoading}
              aria-invalid={Boolean(firstError("destination_account_id"))}
            >
              <option value="">Select an account</option>
              {destinationOptions.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.code} — {account.name}
                  {account.is_active ? "" : " (inactive)"}
                </option>
              ))}
            </select>
          </FormField>
        </div>

        <FormField
          label="Reference"
          htmlFor="cash-bank-transaction-reference"
          error={firstError("reference")}
        >
          <Input
            id="cash-bank-transaction-reference"
            value={reference}
            onChange={(event) => setReference(event.target.value)}
            invalid={Boolean(firstError("reference"))}
            maxLength={255}
            autoComplete="off"
          />
        </FormField>

        <FormField label="Notes" htmlFor="cash-bank-transaction-notes" error={firstError("notes")}>
          <textarea
            id="cash-bank-transaction-notes"
            className={`${textareaClasses} ${firstError("notes") ? "border-danger" : ""}`}
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
            maxLength={5000}
            aria-invalid={Boolean(firstError("notes"))}
          />
        </FormField>

        <p className="flex items-center gap-2 text-xs text-muted-foreground">
          <Badge variant="neutral">{typeChosen ? "Draft" : "New"}</Badge>
          A cash/bank transaction does not affect the ledger until it is posted.
        </p>
      </form>
    </Dialog>
  );
}
