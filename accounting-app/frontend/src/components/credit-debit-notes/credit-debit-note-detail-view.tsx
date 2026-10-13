"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { useAuth } from "@/components/auth/auth-provider";
import { usePermissions } from "@/components/auth/use-permissions";
import { noteStatusLabel, noteStatusVariant } from "@/components/credit-debit-notes/credit-debit-notes-view";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { LoadingState } from "@/components/ui/loading-state";
import { ApiError } from "@/lib/api/errors";
import { deleteCreditDebitNote, postCreditDebitNote } from "@/lib/credit-debit-notes/api";
import { isSalesNote, type NoteType } from "@/lib/credit-debit-notes/types";
import { useCreditDebitNote } from "@/lib/credit-debit-notes/use-credit-debit-note";
import { formatDateOnly, formatTimestamp } from "@/lib/format/date";
import { formatMoney, MONEY_DISPLAY_DECIMALS } from "@/lib/format/money";

export function CreditDebitNoteDetailView({
  noteType,
  basePath,
  noteId,
}: {
  noteType: NoteType;
  basePath: string;
  noteId: number;
}) {
  const router = useRouter();
  const { activeCompany } = useAuth();
  const { can } = usePermissions();
  const companyId = activeCompany?.id ?? null;
  const sales = isSalesNote(noteType);
  const noun = `${sales ? "credit" : "debit"} note`;
  const sourceNoun = sales ? "invoice" : "bill";

  const canView = can("accounting.credit_debit_note.view");
  const canUpdate = can("accounting.credit_debit_note.update");
  const canDelete = can("accounting.credit_debit_note.delete");
  const canPost = can("accounting.credit_debit_note.post");

  const [reloadToken, setReloadToken] = useState(0);
  const [confirm, setConfirm] = useState<"post" | "delete" | null>(null);
  const [working, setWorking] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const state = useCreditDebitNote({ noteId, companyId, enabled: canView, reloadToken });

  const money = (value: string | null | undefined) => formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS });

  const runAction = async () => {
    if (!confirm || working) return;
    setWorking(true);
    setActionError(null);
    try {
      if (confirm === "post") {
        await postCreditDebitNote(noteId);
        setConfirm(null);
        setReloadToken((token) => token + 1);
      } else {
        await deleteCreditDebitNote(noteId);
        router.push(basePath);
      }
    } catch (error) {
      setActionError(
        error instanceof ApiError ? error.message : "We could not complete this action. Please try again.",
      );
      setWorking(false);
    }
  };

  const body = (() => {
    if (!activeCompany) {
      return <EmptyState title="No company selected" description={`Choose a company to view this ${noun}.`} />;
    }
    if (!canView) {
      return <EmptyState title="Note unavailable" description="Your role does not include access to credit and debit notes." />;
    }
    if (state.status === "loading") {
      return <LoadingState label={`Loading ${noun}…`} />;
    }
    if (state.status === "notfound") {
      return <EmptyState title="Note not found" description={`This ${noun} does not exist for the selected company.`} />;
    }
    if (state.status === "unavailable") {
      return <EmptyState title="Note unavailable" description={state.message} />;
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
    if (state.data.note_type !== noteType) {
      return <EmptyState title="Note not found" description={`This record is not a ${noun}.`} />;
    }

    const note = state.data;
    const isDraft = note.status === "DRAFT";
    const sourceHref =
      note.source_document_type === "sales_invoice"
        ? `/sales-invoices/${note.source_document_id}`
        : `/purchase-bills/${note.source_document_id}`;

    return (
      <div className="flex flex-col gap-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-semibold text-foreground">{note.note_number}</h1>
              <Badge variant={noteStatusVariant(note.status)}>{noteStatusLabel(note.status)}</Badge>
              <Badge variant={note.is_credit ? "success" : "warning"}>{note.label}</Badge>
            </div>
            <p className="mt-1 text-sm text-muted-foreground">
              {note.customer
                ? `${note.customer.code} — ${note.customer.name}`
                : note.supplier
                  ? `${note.supplier.code} — ${note.supplier.name}`
                  : "—"}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            {canUpdate && isDraft ? (
              <Link href={`${basePath}/${note.id}/edit`}>
                <Button variant="secondary" size="sm">
                  Edit draft
                </Button>
              </Link>
            ) : null}
            {canPost && isDraft ? (
              <Button
                size="sm"
                onClick={() => {
                  setActionError(null);
                  setConfirm("post");
                }}
              >
                Post note
              </Button>
            ) : null}
            {canDelete && isDraft ? (
              <Button
                size="sm"
                variant="danger"
                onClick={() => {
                  setActionError(null);
                  setConfirm("delete");
                }}
              >
                Delete draft
              </Button>
            ) : null}
            <Link href={basePath}>
              <Button variant="ghost" size="sm">
                Back
              </Button>
            </Link>
          </div>
        </div>

        <div className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <div className="text-xs uppercase text-muted-foreground">Note date</div>
            <div className="text-sm">{formatDateOnly(note.note_date)}</div>
          </div>
          <div>
            <div className="text-xs uppercase text-muted-foreground">Adjusts</div>
            <div className="text-sm">
              <Link className="text-foreground hover:underline" href={sourceHref}>
                {note.source_document_number ?? `#${note.source_document_id}`}
              </Link>
            </div>
          </div>
          <div>
            <div className="text-xs uppercase text-muted-foreground">Posted</div>
            <div className="text-sm">{note.posted_at ? formatTimestamp(note.posted_at) : "Not posted"}</div>
          </div>
          <div>
            <div className="text-xs uppercase text-muted-foreground">Journal</div>
            <div className="text-sm">{note.journal_id ?? "—"}</div>
          </div>
        </div>

        <div className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2">
          <div>
            <div className="text-xs uppercase text-muted-foreground">Reference</div>
            <div className="text-sm">{note.reference ?? "—"}</div>
          </div>
          <div>
            <div className="text-xs uppercase text-muted-foreground">Tax account</div>
            <div className="text-sm">{note.tax_account_id ?? "—"}</div>
          </div>
          <div className="sm:col-span-2">
            <div className="text-xs uppercase text-muted-foreground">Reason</div>
            <p className="mt-1 whitespace-pre-wrap text-sm text-foreground">{note.reason}</p>
          </div>
        </div>

        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="min-w-full divide-y divide-border text-sm">
            <thead className="bg-surface-muted">
              <tr>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">#</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Description</th>
                <th className="px-3 py-2 text-left font-medium text-muted-foreground">Adjusts {sourceNoun} line</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Qty</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Unit price</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Discount</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Tax</th>
                <th className="px-3 py-2 text-right font-medium text-muted-foreground">Line total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {note.lines.map((line) => (
                <tr key={line.id}>
                  <td className="px-3 py-2">{line.line_number}</td>
                  <td className="px-3 py-2">{line.description ?? "—"}</td>
                  <td className="px-3 py-2 text-muted-foreground">
                    {line.source_line_id !== null ? `#${line.source_line_id}` : "Document-level"}
                  </td>
                  <td className="px-3 py-2 text-right">{money(line.quantity)}</td>
                  <td className="px-3 py-2 text-right">{money(line.unit_price)}</td>
                  <td className="px-3 py-2 text-right">{money(line.discount)}</td>
                  <td className="px-3 py-2 text-right">{money(line.tax_amount)}</td>
                  <td className="px-3 py-2 text-right">{money(line.line_total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Subtotal</div>
            <div className="text-lg font-semibold">{money(note.subtotal)}</div>
          </div>
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Discount</div>
            <div className="text-lg font-semibold">{money(note.discount_total)}</div>
          </div>
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Tax</div>
            <div className="text-lg font-semibold">{money(note.tax_total)}</div>
          </div>
          <div className="rounded-lg border border-border bg-surface p-4">
            <div className="text-sm text-muted-foreground">Grand total</div>
            <div className="text-lg font-semibold">{money(note.grand_total)}</div>
          </div>
        </div>

        {note.notes ? (
          <div className="rounded-lg border border-border bg-surface-muted/40 p-4 text-sm">
            <div className="font-medium text-foreground">Notes</div>
            <p className="mt-1 whitespace-pre-wrap text-muted-foreground">{note.notes}</p>
          </div>
        ) : null}

        <Dialog
          open={confirm !== null}
          title={confirm === "delete" ? `Delete draft ${noun}` : `Post ${noun}`}
          description={
            confirm === "delete"
              ? `Delete draft ${note.note_number}? This cannot be undone and the number is not reused.`
              : `Post ${note.note_number}? Posting writes the accounting entries and applies the adjustment.`
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
                variant={confirm === "delete" ? "danger" : "primary"}
                loading={working}
                onClick={runAction}
              >
                {confirm === "delete" ? "Delete" : "Post"}
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
  })();

  return body;
}
