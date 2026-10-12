"use client";

import { useState, type FormEvent } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { ApiError } from "@/lib/api/errors";
import { updateCashBankAccount } from "@/lib/cash-bank/api";
import { CASH_BANK_KIND_VALUES, type CashBankAccount, type CashBankAccountClassifyPayload, type CashBankKindValue } from "@/lib/cash-bank/types";
import { validateCashBankAccountClassify } from "@/lib/cash-bank/validators";

import type { CashBankBankDetailsAction } from "./cash-bank-account-actions-dialog";

/**
 * Manage one classified account: its cash/bank classification and, for a bank
 * account, its bank details.
 *
 * Classification and bank details are one endpoint (`PUT
 * /cash-bank-accounts/{account}`) because they are one user action in a form.
 * The backend owns the rules that make a change impermissible — an account with
 * bank details cannot become cash, an account with accounting history keeps its
 * classification — so those refusals are surfaced verbatim rather than guessed
 * at here.
 *
 * The bank-details lifecycle (activate / deactivate / remove) is delegated to a
 * confirm dialog: the parent closes this dialog and opens that one, so the two
 * overlays never stack. The classification change is made from the chart of
 * accounts screen; only accounts that are still cash/bank reach this dialog.
 */

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function kindLabel(value: CashBankKindValue): string {
  return value === "CASH" ? "Cash" : "Bank";
}

function toFieldErrors(client: Record<string, string>): Record<string, string[]> {
  return Object.fromEntries(Object.entries(client).map(([key, value]) => [key, [value]]));
}

export function CashBankAccountDialog({
  editor,
  onClose,
  onSaved,
  onBankDetailAction,
}: {
  editor: CashBankAccount | null;
  onClose: () => void;
  onSaved: () => Promise<void> | void;
  onBankDetailAction: (action: CashBankBankDetailsAction) => void;
}) {
  const open = editor !== null;
  const account = editor;

  const [kind, setKind] = useState<CashBankKindValue | "">(account?.cash_bank_kind ?? "");
  const [accountName, setAccountName] = useState(account?.bank_account?.account_name ?? "");
  const [bankName, setBankName] = useState(account?.bank_account?.bank_name ?? "");
  const [accountNumber, setAccountNumber] = useState(account?.bank_account?.account_number ?? "");
  const [branch, setBranch] = useState(account?.bank_account?.branch ?? "");
  const [bankIdentifier, setBankIdentifier] = useState(account?.bank_account?.bank_identifier ?? "");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  const firstError = (key: string): string | undefined => fieldErrors[key]?.[0];

  const existingDetails = account?.bank_account ?? null;

  const toPayload = (): CashBankAccountClassifyPayload => {
    const payload: CashBankAccountClassifyPayload = {
      cash_bank_kind: kind === "" ? null : kind,
    };

    if (kind === "BANK" && (accountName.trim() !== "" || bankName.trim() !== "" || existingDetails)) {
      payload.bank_account = {
        account_name: accountName.trim(),
        bank_name: bankName.trim(),
        account_number: accountNumber.trim() === "" ? null : accountNumber.trim(),
        branch: branch.trim() === "" ? null : branch.trim(),
        bank_identifier: bankIdentifier.trim() === "" ? null : bankIdentifier.trim(),
      };
    }

    return payload;
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFieldErrors({});
    setFormError(null);

    if (account === null) {
      return;
    }

    const payload = toPayload();
    const clientErrors = validateCashBankAccountClassify(payload);

    if (Object.keys(clientErrors).length > 0) {
      setFieldErrors(toFieldErrors(clientErrors));
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setPending(true);

    try {
      await updateCashBankAccount(account.id, payload);
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

  return (
    <Dialog
      open={open}
      title={account ? `Manage ${account.code}` : "Manage account"}
      description={
        account
          ? `${account.name} — classification and bank details.`
          : undefined
      }
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Close
          </Button>
          <Button type="submit" form="cash-bank-account-form" loading={pending}>
            Save changes
          </Button>
        </>
      }
    >
      {account ? (
        <form
          id="cash-bank-account-form"
          onSubmit={handleSubmit}
          noValidate
          className="flex flex-col gap-4"
        >
          {formError ? (
            <ErrorState title="Could not update the account" message={formError} />
          ) : null}

          <FormField
            label="Classification"
            htmlFor="cash-bank-kind"
            required
            hint="Cash accounts have no bank details. Bank accounts may carry them."
            error={firstError("cash_bank_kind")}
          >
            <select
              id="cash-bank-kind"
              className={`${selectClasses} ${firstError("cash_bank_kind") ? "border-danger" : ""}`}
              value={kind}
              onChange={(event) => setKind(event.target.value as CashBankKindValue | "")}
              aria-invalid={Boolean(firstError("cash_bank_kind"))}
            >
              <option value="">Select a classification</option>
              {CASH_BANK_KIND_VALUES.map((value) => (
                <option key={value} value={value}>
                  {kindLabel(value)}
                </option>
              ))}
            </select>
          </FormField>

          {kind === "BANK" ? (
            <fieldset className="flex flex-col gap-4 rounded-lg border border-border p-4">
              <legend className="px-1 text-sm font-medium text-foreground">
                Bank details
              </legend>

              {existingDetails ? (
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={existingDetails.is_active ? "success" : "neutral"}>
                    {existingDetails.is_active ? "Active" : "Inactive"}
                  </Badge>
                  <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    onClick={() =>
                      onBankDetailAction({
                        kind: existingDetails.is_active ? "deactivate" : "activate",
                        account,
                      })
                    }
                  >
                    {existingDetails.is_active ? "Deactivate bank details" : "Activate bank details"}
                  </Button>
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    className="text-danger hover:bg-danger-soft hover:text-danger"
                    onClick={() => onBankDetailAction({ kind: "remove", account })}
                  >
                    Remove bank details
                  </Button>
                </div>
              ) : (
                <p className="text-xs text-muted-foreground">
                  No bank details recorded yet. Fill them in below and save.
                </p>
              )}

              <FormField
                label="Account name"
                htmlFor="cash-bank-account-name"
                required
                error={firstError("bank_account.account_name")}
              >
                <Input
                  id="cash-bank-account-name"
                  value={accountName}
                  onChange={(event) => setAccountName(event.target.value)}
                  invalid={Boolean(firstError("bank_account.account_name"))}
                  maxLength={255}
                  autoComplete="off"
                />
              </FormField>

              <FormField
                label="Bank name"
                htmlFor="cash-bank-bank-name"
                required
                error={firstError("bank_account.bank_name")}
              >
                <Input
                  id="cash-bank-bank-name"
                  value={bankName}
                  onChange={(event) => setBankName(event.target.value)}
                  invalid={Boolean(firstError("bank_account.bank_name"))}
                  maxLength={255}
                  autoComplete="off"
                />
              </FormField>

              <FormField
                label="Account number"
                htmlFor="cash-bank-account-number"
                error={firstError("bank_account.account_number")}
              >
                <Input
                  id="cash-bank-account-number"
                  value={accountNumber}
                  onChange={(event) => setAccountNumber(event.target.value)}
                  maxLength={100}
                  autoComplete="off"
                />
              </FormField>

              <FormField
                label="Branch"
                htmlFor="cash-bank-branch"
                error={firstError("bank_account.branch")}
              >
                <Input
                  id="cash-bank-branch"
                  value={branch}
                  onChange={(event) => setBranch(event.target.value)}
                  maxLength={255}
                  autoComplete="off"
                />
              </FormField>

              <FormField
                label="Bank identifier"
                htmlFor="cash-bank-identifier"
                hint="Routing / sort / SWIFT code, if known."
                error={firstError("bank_account.bank_identifier")}
              >
                <Input
                  id="cash-bank-identifier"
                  value={bankIdentifier}
                  onChange={(event) => setBankIdentifier(event.target.value)}
                  maxLength={100}
                  autoComplete="off"
                />
              </FormField>
            </fieldset>
          ) : null}
        </form>
      ) : null}
    </Dialog>
  );
}
