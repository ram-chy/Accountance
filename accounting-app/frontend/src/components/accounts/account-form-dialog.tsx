"use client";

import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { ApiError } from "@/lib/api/errors";

import { createAccount, updateAccount } from "@/lib/accounts/api";
import {
  ACCOUNT_TYPE_VALUES,
  CASH_BANK_KIND_VALUES,
  NORMAL_BALANCE_VALUES,
  TYPE_NORMAL_BALANCE,
  type Account,
  type AccountTypeMeta,
  type AccountTypeValue,
  type AccountWritePayload,
  type CashBankKindValue,
  type NormalBalanceValue,
} from "@/lib/accounts/types";
import { validateAccountWrite } from "@/lib/accounts/validators";

/**
 * Create / edit account dialog.
 *
 * Controlled inputs keep the user's entries across a failed submit; the parent
 * remounts this component (via `key`) when switching between records or modes,
 * which resets state cleanly. Validation mirrors the verified Laravel rules for
 * immediate feedback; the backend remains authoritative and its 422 field
 * errors are shown verbatim. Server-owned fields are never sent.
 */

type EditorMode = { mode: "create" } | { mode: "edit"; account: Account };

type AccountFormDialogProps = {
  editor: EditorMode | null;
  onClose: () => void;
  onSaved: () => Promise<void> | void;
  types: AccountTypeMeta[];
};

const selectClasses =
  "h-10 w-full rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

function labelForType(value: AccountTypeValue | NormalBalanceValue | CashBankKindValue): string {
  return value.charAt(0) + value.slice(1).toLowerCase();
}

function formToPayload(form: {
  code: string;
  name: string;
  account_type: AccountTypeValue | "";
  cash_bank_kind: CashBankKindValue | "";
  normal_balance: NormalBalanceValue | "";
  description: string;
}): AccountWritePayload {
  return {
    code: form.code.trim(),
    name: form.name.trim(),
    account_type: form.account_type as AccountTypeValue,
    cash_bank_kind: form.cash_bank_kind === "" ? null : form.cash_bank_kind,
    normal_balance: form.normal_balance === "" ? null : form.normal_balance,
    description: form.description.trim() === "" ? null : form.description.trim(),
  };
}

function toFieldErrors(client: Record<string, string>): Record<string, string[]> {
  return Object.fromEntries(Object.entries(client).map(([key, value]) => [key, [value]]));
}

export function AccountFormDialog({
  editor,
  onClose,
  onSaved,
  types,
}: AccountFormDialogProps) {
  const isEdit = editor?.mode === "edit";
  const account = isEdit ? editor.account : null;
  const open = editor !== null;

  const [code, setCode] = useState(account?.code ?? "");
  const [name, setName] = useState(account?.name ?? "");
  const [account_type, setAccountType] = useState<AccountTypeValue | "">(
    account?.account_type ?? "",
  );
  const [cash_bank_kind, setCashBankKind] = useState<CashBankKindValue | "">(
    account?.cash_bank_kind ?? "",
  );
  const [normal_balance, setNormalBalance] = useState<NormalBalanceValue | "">(
    account && account.is_contra ? account.normal_balance : "",
  );
  const [description, setDescription] = useState(account?.description ?? "");
  const [pending, setPending] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);

  const typeOptions =
    types.length > 0
      ? types
      : ACCOUNT_TYPE_VALUES.map((value) => ({
          value,
          normal_balance: TYPE_NORMAL_BALANCE[value],
        }));

  const selectedType = typeOptions.find((option) => option.value === account_type);
  const effectiveBalance =
    normal_balance === "" ? selectedType?.normal_balance ?? null : normal_balance;

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFieldErrors({});
    setFormError(null);

    const payload = formToPayload({
      code,
      name,
      account_type,
      cash_bank_kind,
      normal_balance,
      description,
    });

    const clientErrors = validateAccountWrite(payload);

    if (Object.keys(clientErrors).length > 0) {
      setFieldErrors(toFieldErrors(clientErrors));
      setFormError("Please correct the highlighted fields.");
      return;
    }

    setPending(true);

    try {
      if (account === null) {
        await createAccount(payload);
      } else {
        await updateAccount(account.id, payload);
      }
      await onSaved();
      onClose();
    } catch (error) {
      if (error instanceof ApiError && error.isValidation && error.errors) {
        setFieldErrors(error.errors);
        setFormError("Please correct the highlighted fields.");
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
      title={isEdit ? `Edit account ${account?.code ?? ""}`.trim() : "New account"}
      description={
        isEdit
          ? "Changes are validated by the accounting service before they are saved."
          : "A new account starts active and becomes available for journal postings."
      }
      onClose={pending ? () => undefined : onClose}
      footer={
        <>
          <Button variant="secondary" disabled={pending} onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="account-form" loading={pending}>
            {isEdit ? "Save changes" : "Create account"}
          </Button>
        </>
      }
    >
      <form id="account-form" onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
        {formError ? (
          <ErrorState title="Could not save the account" message={formError} />
        ) : null}

        <FormField label="Account code" htmlFor="account-code" required error={fieldErrors.code?.[0]}>
          <Input
            id="account-code"
            name="code"
            value={code}
            onChange={(event) => setCode(event.target.value)}
            invalid={Boolean(fieldErrors.code)}
            aria-describedby={fieldErrors.code ? "account-code-error" : undefined}
            maxLength={50}
            autoComplete="off"
          />
        </FormField>

        <FormField label="Account name" htmlFor="account-name" required error={fieldErrors.name?.[0]}>
          <Input
            id="account-name"
            name="name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            invalid={Boolean(fieldErrors.name)}
            aria-describedby={fieldErrors.name ? "account-name-error" : undefined}
            maxLength={255}
            autoComplete="off"
          />
        </FormField>

        <FormField
          label="Account type"
          htmlFor="account-type"
          required
          hint={
            account_type !== ""
              ? `Default normal balance for ${labelForType(account_type)} is ${effectiveBalance}.`
              : undefined
          }
          error={fieldErrors.account_type?.[0]}
        >
          <select
            id="account-type"
            name="account_type"
            className={`${selectClasses} ${fieldErrors.account_type ? "border-danger" : ""}`}
            value={account_type}
            onChange={(event) => setAccountType(event.target.value as AccountTypeValue | "")}
            required
            aria-invalid={Boolean(fieldErrors.account_type)}
            aria-describedby={fieldErrors.account_type ? "account-type-error" : undefined}
          >
            <option value="">Select a type</option>
            {ACCOUNT_TYPE_VALUES.map((value) => (
              <option key={value} value={value}>
                {labelForType(value)}
              </option>
            ))}
          </select>
        </FormField>

        <FormField
          label="Normal balance"
          htmlFor="account-normal-balance"
          hint={
            normal_balance === "" && account_type !== ""
              ? "“Follow account type” means a regular account. Choosing the opposite side creates a contra account."
              : undefined
          }
          error={fieldErrors.normal_balance?.[0]}
        >
          <select
            id="account-normal-balance"
            name="normal_balance"
            className={`${selectClasses} ${fieldErrors.normal_balance ? "border-danger" : ""}`}
            value={normal_balance}
            onChange={(event) => setNormalBalance(event.target.value as NormalBalanceValue | "")}
            aria-invalid={Boolean(fieldErrors.normal_balance)}
            aria-describedby={fieldErrors.normal_balance ? "account-normal-balance-error" : undefined}
          >
            <option value="">Follow account type</option>
            {NORMAL_BALANCE_VALUES.map((value) => (
              <option key={value} value={value}>
                {labelForType(value)}
              </option>
            ))}
          </select>
        </FormField>

        <FormField
          label="Cash or bank account"
          htmlFor="account-cash-bank-kind"
          hint="Optional. Marking an account as Cash or Bank makes it available to cash and bank transactions."
          error={fieldErrors.cash_bank_kind?.[0]}
        >
          <select
            id="account-cash-bank-kind"
            name="cash_bank_kind"
            className={`${selectClasses} ${fieldErrors.cash_bank_kind ? "border-danger" : ""}`}
            value={cash_bank_kind}
            onChange={(event) => setCashBankKind(event.target.value as CashBankKindValue | "")}
            aria-invalid={Boolean(fieldErrors.cash_bank_kind)}
            aria-describedby={fieldErrors.cash_bank_kind ? "account-cash-bank-kind-error" : undefined}
          >
            <option value="">Not a cash or bank account</option>
            {CASH_BANK_KIND_VALUES.map((value) => (
              <option key={value} value={value}>
                {labelForType(value)}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Description" htmlFor="account-description" error={fieldErrors.description?.[0]}>
          <textarea
            id="account-description"
            name="description"
            className={`${selectClasses} h-auto min-h-20 resize-y py-2 ${fieldErrors.description ? "border-danger" : ""}`}
            value={description}
            onChange={(event) => setDescription(event.target.value)}
            aria-invalid={Boolean(fieldErrors.description)}
            aria-describedby={fieldErrors.description ? "account-description-error" : undefined}
            maxLength={1000}
          />
        </FormField>
      </form>
    </Dialog>
  );
}