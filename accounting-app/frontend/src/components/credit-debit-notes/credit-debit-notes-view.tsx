"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { FormField, Input } from "@/components/ui/field";
import { LoadingState } from "@/components/ui/loading-state";
import { ApiError } from "@/lib/api/errors";
import {
  deleteCreditDebitNote,
  postCreditDebitNote,
} from "@/lib/credit-debit-notes/api";
import {
  isSalesNote,
  type CreditDebitNote,
  type CreditDebitNoteListQuery,
  type NoteStatus,
  type NoteType,
} from "@/lib/credit-debit-notes/types";
import { CREDIT_DEBIT_NOTE_LIST_PER_PAGE, useCreditDebitNotes } from "@/lib/credit-debit-notes/use-credit-debit-notes";
import { useCustomerOptions } from "@/lib/customers/use-customer-options";
import { formatDateOnly } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";
import { useSupplierOptions } from "@/lib/suppliers/use-supplier-options";

const selectClasses =
  "h-10 rounded-md border border-border bg-surface px-3 text-sm text-foreground " +
  "transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring " +
  "disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground";

export function noteStatusVariant(status: NoteStatus): "neutral" | "info" {
  return status === "POSTED" ? "info" : "neutral";
}

export function noteStatusLabel(status: NoteStatus): string {
  return status === "POSTED" ? "Posted" : "Draft";
}

/** Page title for a note type; only the two exposed types have routes. */
export function noteCollectionTitle(noteType: NoteType): string {
  return noteType === "SALES_CREDIT_NOTE" ? "Credit Notes" : "Debit Notes";
}

function counterpartyLabel(note: CreditDebitNote): string {
  return note.customer?.name ?? note.supplier?.name ?? "—";
}

function sourceHref(note: CreditDebitNote): string {
  return note.source_document_type === "sales_invoice"
    ? `/sales-invoices/${note.source_document_id}`
    : `/purchase-bills/${note.source_document_id}`;
}

type Filters = {
  search: string;
  status: NoteStatus | "";
  customer_id: number | null;
  supplier_id: number | null;
  from: string;
  to: string;
};

const DEFAULT_FILTERS: Filters = { search: "", status: "", customer_id: null, supplier_id: null, from: "", to: "" };

function toQuery(noteType: NoteType, filters: Filters, page: number): CreditDebitNoteListQuery {
  return {
    note_type: noteType,
    search: filters.search.trim() || undefined,
    status: filters.status || undefined,
    customer_id: filters.customer_id ?? undefined,
    supplier_id: filters.supplier_id ?? undefined,
    from: filters.from || undefined,
    to: filters.to || undefined,
    page,
    per_page: CREDIT_DEBIT_NOTE_LIST_PER_PAGE,
  };
}

export function CreditDebitNotesView({ noteType, basePath }: { noteType: NoteType; basePath: string }) {
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const sales = isSalesNote(noteType);

  const canView = can("accounting.credit_debit_note.view");
  const canCreate = can("accounting.credit_debit_note.create");
  const canUpdate = can("accounting.credit_debit_note.update");
  const canDelete = can("accounting.credit_debit_note.delete");
  const canPost = can("accounting.credit_debit_note.post");

  const [draft, setDraft] = useState<Filters>(DEFAULT_FILTERS);
  const [applied, setApplied] = useState<Filters>(DEFAULT_FILTERS);
  const [page, setPage] = useState(1);
  const [reloadToken, setReloadToken] = useState(0);

  const [confirm, setConfirm] = useState<{ note: CreditDebitNote; action: "post" | "delete" } | null>(null);
  const [working, setWorking] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const customersState = useCustomerOptions(companyId, canView && sales);
  const suppliersState = useSupplierOptions(companyId, canView && !sales);
  const counterparties = sales
    ? customersState.status === "ready"
      ? customersState.items
      : []
    : suppliersState.status === "ready"
      ? suppliersState.items
      : [];

  const state = useCreditDebitNotes({ companyId, enabled: canView, query: toQuery(noteType, applied, page), reloadToken });

  const apply = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setApplied(draft);
    setPage(1);
  };

  const clear = () => {
    setDraft(DEFAULT_FILTERS);
    setApplied(DEFAULT_FILTERS);
    setPage(1);
  };

  const runAction = async () => {
    if (!confirm || working) return;
    setWorking(true);
    setActionError(null);
    try {
      if (confirm.action === "post") {
        await postCreditDebitNote(confirm.note.id);
      } else {
        await deleteCreditDebitNote(confirm.note.id);
      }
      setConfirm(null);
      setReloadToken((token) => token + 1);
    } catch (error) {
      setActionError(
        error instanceof ApiError ? error.message : "We could not complete this action. Please try again.",
      );
    } finally {
      setWorking(false);
    }
  };

  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });
  const title = noteCollectionTitle(noteType);

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description={`Choose a company to view its ${title.toLowerCase()}.`} />;
    }
    if (!canView) {
      return <EmptyState title="Notes unavailable" description="Your role does not include access to credit and debit notes." />;
    }
    if (state.status === "loading") {
      return <LoadingState label={`Loading ${title.toLowerCase()}…`} />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Notes unavailable" description={state.message} />;
    }
    if (state.status === "error") {
      return (
        <ErrorState
          message={state.message}
          requestId={state.requestId}
          action={
            <Button type="button" onClick={() => setReloadToken((token) => token + 1)}>
              Retry
            </Button>
          }
        />
      );
    }
    if (state.items.length === 0) {
      return page > 1 ? (
        <EmptyState title="No notes on this page" description="Go back to the previous page." />
      ) : (
        <EmptyState title={`No ${title.toLowerCase()} yet`} description="Create your first note to get started." />
      );
    }

    return (
      <div className="flex flex-col gap-4">
        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead className="bg-surface-muted">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Note</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Counterparty</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Adjusts</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Date</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Total</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Status</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {state.items.map((note) => (
                <tr key={note.id}>
                  <td className="px-3 py-2 font-mono text-xs">
                    <Link className="text-foreground hover:underline" href={`${basePath}/${note.id}`}>
                      {note.note_number}
                    </Link>
                  </td>
                  <td className="px-3 py-2">{counterpartyLabel(note)}</td>
                  <td className="px-3 py-2 font-mono text-xs">
                    <Link className="text-foreground hover:underline" href={sourceHref(note)}>
                      {note.source_document_number ?? `#${note.source_document_id}`}
                    </Link>
                  </td>
                  <td className="px-3 py-2 text-muted-foreground">{formatDateOnly(note.note_date)}</td>
                  <td className="px-3 py-2 text-right">{money(note.grand_total)}</td>
                  <td className="px-3 py-2">
                    <Badge variant={noteStatusVariant(note.status)}>{noteStatusLabel(note.status)}</Badge>
                  </td>
                  <td className="px-3 py-2">
                    <div className="flex justify-end gap-2">
                      {canUpdate && note.status === "DRAFT" ? (
                        <Link href={`${basePath}/${note.id}/edit`}>
                          <Button size="sm" variant="secondary">
                            Edit
                          </Button>
                        </Link>
                      ) : null}
                      {canPost && note.status === "DRAFT" ? (
                        <Button
                          size="sm"
                          variant="primary"
                          onClick={() => {
                            setActionError(null);
                            setConfirm({ note, action: "post" });
                          }}
                        >
                          Post
                        </Button>
                      ) : null}
                      {canDelete && note.status === "DRAFT" ? (
                        <Button
                          size="sm"
                          variant="danger"
                          onClick={() => {
                            setActionError(null);
                            setConfirm({ note, action: "delete" });
                          }}
                        >
                          Delete
                        </Button>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="flex items-center justify-between">
          <Button
            type="button"
            variant="secondary"
            size="sm"
            disabled={page <= 1}
            onClick={() => setPage((current) => Math.max(1, current - 1))}
          >
            Previous
          </Button>
          <span className="text-sm text-muted-foreground">Page {page}</span>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            disabled={!state.hasMore}
            onClick={() => setPage((current) => current + 1)}
          >
            Next
          </Button>
        </div>
      </div>
    );
  })();

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">{title}</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {sales
              ? "Adjust posted customer invoices with credit notes."
              : "Adjust posted supplier bills with debit notes."}
          </p>
        </div>
        {canCreate ? (
          <Link href={`${basePath}/new`}>
            <Button type="button">New {sales ? "credit" : "debit"} note</Button>
          </Link>
        ) : null}
      </div>

      <form
        onSubmit={apply}
        className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:flex-wrap sm:items-end"
      >
        <FormField label="Search" htmlFor="note-search">
          <Input
            id="note-search"
            value={draft.search}
            placeholder="Number, reason, reference…"
            onChange={(event) => setDraft((current) => ({ ...current, search: event.target.value }))}
          />
        </FormField>
        <FormField label={sales ? "Customer" : "Supplier"} htmlFor="note-counterparty">
          <select
            id="note-counterparty"
            className={selectClasses}
            value={
              sales
                ? draft.customer_id === null
                  ? ""
                  : String(draft.customer_id)
                : draft.supplier_id === null
                  ? ""
                  : String(draft.supplier_id)
            }
            onChange={(event) => {
              const value = event.target.value === "" ? null : Number(event.target.value);
              setDraft((current) => (sales ? { ...current, customer_id: value } : { ...current, supplier_id: value }));
            }}
            disabled={(sales ? customersState : suppliersState).status !== "ready"}
          >
            <option value="">All</option>
            {counterparties.map((option) => (
              <option key={option.id} value={option.id}>
                {option.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Status" htmlFor="note-status">
          <select
            id="note-status"
            className={selectClasses}
            value={draft.status}
            onChange={(event) => setDraft((current) => ({ ...current, status: event.target.value as NoteStatus | "" }))}
          >
            <option value="">All</option>
            <option value="DRAFT">Draft</option>
            <option value="POSTED">Posted</option>
          </select>
        </FormField>
        <FormField label="From" htmlFor="note-from">
          <Input
            id="note-from"
            type="date"
            value={draft.from}
            onChange={(event) => setDraft((current) => ({ ...current, from: event.target.value }))}
          />
        </FormField>
        <FormField label="To" htmlFor="note-to">
          <Input
            id="note-to"
            type="date"
            value={draft.to}
            onChange={(event) => setDraft((current) => ({ ...current, to: event.target.value }))}
          />
        </FormField>
        <div className="flex gap-2 pb-1.5">
          <Button type="submit">Apply</Button>
          <Button type="button" variant="secondary" onClick={clear}>
            Clear
          </Button>
        </div>
      </form>

      {body}

      <Dialog
        open={confirm !== null}
        title={confirm?.action === "delete" ? "Delete draft note" : "Post note"}
        description={
          confirm
            ? confirm.action === "delete"
              ? `Delete draft ${confirm.note.note_number}? This cannot be undone and the number is not reused.`
              : `Post ${confirm.note.note_number}? Posting writes the accounting entries and applies the adjustment.`
            : undefined
        }
        onClose={() => {
          if (!working) {
            setConfirm(null);
            setActionError(null);
          }
        }}
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setConfirm(null);
                setActionError(null);
              }}
              disabled={working}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant={confirm?.action === "delete" ? "danger" : "primary"}
              loading={working}
              onClick={runAction}
            >
              {confirm?.action === "delete" ? "Delete" : "Post"}
            </Button>
          </>
        }
      >
        {actionError ? (
          <p role="alert" className="rounded-md border border-danger-border bg-danger-soft px-3 py-2 text-sm text-danger-soft-foreground">
            {actionError}
          </p>
        ) : null}
      </Dialog>
    </div>
  );
}
