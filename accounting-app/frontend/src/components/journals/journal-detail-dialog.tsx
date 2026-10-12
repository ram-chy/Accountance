"use client";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { formatDateOnly, formatTimestamp } from "@/lib/format/date";
import { MONEY_DISPLAY_DECIMALS, formatMoney } from "@/lib/format/money";
import type { Journal, JournalLine } from "@/lib/journals/types";

import type { JournalAction } from "./journal-confirm-dialog";

/**
 * Read-only journal detail.
 *
 * Shows the header, every line and the derived totals. Posted journals are
 * immutable, so their only action is Close; drafts expose Edit/Post/Delete when
 * the caller holds the matching permission. All figures come from the validated
 * row (the index endpoint loads lines), never from a local recomputation.
 */

type JournalDetailDialogProps = {
  journal: Journal | null;
  canUpdate: boolean;
  canDelete: boolean;
  canPost: boolean;
  onClose: () => void;
  onEdit: (journal: Journal) => void;
  onAction: (action: JournalAction) => void;
};

function money(value: string | null): string {
  return value ? formatMoney(value, { decimals: MONEY_DISPLAY_DECIMALS }) : "—";
}

function ForeignCell({ line }: { line: JournalLine }) {
  if (!line.currency_code || (line.foreign_debit === null && line.foreign_credit === null)) {
    return null;
  }

  const foreign = line.foreign_debit ?? line.foreign_credit;
  const rate = line.exchange_rate ? ` @ ${line.exchange_rate}` : "";

  return (
    <span className="block text-xs text-muted-foreground">
      {money(foreign)} {line.currency_code}
      {rate}
    </span>
  );
}

function sourceLabel(value: Journal["source_type"]): string | null {
  if (!value || value === "MANUAL") {
    return null;
  }

  return value
    .toLowerCase()
    .split("_")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");
}

export function JournalDetailDialog({
  journal,
  canUpdate,
  canDelete,
  canPost,
  onClose,
  onEdit,
  onAction,
}: JournalDetailDialogProps) {
  if (journal === null) {
    return null;
  }

  const isDraft = journal.status === "DRAFT";
  const source = sourceLabel(journal.source_type);

  const footer = (
    <>
      <Button variant="secondary" onClick={onClose}>
        Close
      </Button>
      {isDraft && canDelete ? (
        <Button
          variant="danger"
          onClick={() => onAction({ kind: "delete", journal })}
        >
          Delete draft
        </Button>
      ) : null}
      {isDraft && canUpdate ? (
        <Button variant="secondary" onClick={() => onEdit(journal)}>
          Edit
        </Button>
      ) : null}
      {isDraft && canPost ? (
        <Button onClick={() => onAction({ kind: "post", journal })}>Post</Button>
      ) : null}
    </>
  );

  return (
    <Dialog
      open
      title={journal.journal_number}
      onClose={onClose}
      className="max-w-3xl"
      footer={footer}
    >
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={isDraft ? "warning" : "success"}>
            {isDraft ? "Draft" : "Posted"}
          </Badge>
          {source ? <Badge variant="info">{source}</Badge> : null}
          {journal.totals && !journal.totals.is_balanced ? (
            <Badge variant="danger">Unbalanced</Badge>
          ) : null}
        </div>

        <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
          <div className="flex flex-col gap-0.5">
            <dt className="text-xs font-medium text-muted-foreground">Date</dt>
            <dd className="text-sm text-foreground">{formatDateOnly(journal.journal_date)}</dd>
          </div>
          <div className="flex flex-col gap-0.5">
            <dt className="text-xs font-medium text-muted-foreground">Reference</dt>
            <dd className="text-sm text-foreground">{journal.reference ?? "—"}</dd>
          </div>
          <div className="flex flex-col gap-0.5 sm:col-span-2">
            <dt className="text-xs font-medium text-muted-foreground">Description</dt>
            <dd className="text-sm text-foreground">{journal.description ?? "—"}</dd>
          </div>
          {journal.status === "POSTED" ? (
            <div className="flex flex-col gap-0.5">
              <dt className="text-xs font-medium text-muted-foreground">Posted</dt>
              <dd className="text-sm text-foreground">
                {formatTimestamp(journal.posted_at)}
              </dd>
            </div>
          ) : null}
        </dl>

        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full border-collapse text-left">
            <caption className="sr-only">Journal lines</caption>
            <thead>
              <tr className="border-b border-border bg-surface-muted">
                <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
                  #
                </th>
                <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
                  Account
                </th>
                <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
                  Description
                </th>
                <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
                  Debit
                </th>
                <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
                  Credit
                </th>
              </tr>
            </thead>
            <tbody>
              {journal.lines.map((line) => (
                <tr key={line.id} className="border-b border-border last:border-0">
                  <td className="px-3 py-2 text-sm text-muted-foreground">{line.line_number}</td>
                  <td className="px-3 py-2 text-sm text-foreground">
                    <span className="font-mono text-xs text-muted-foreground">
                      {line.account_code ?? line.account_id}
                    </span>
                    <span className="ml-2">{line.account_name ?? "—"}</span>
                  </td>
                  <td className="px-3 py-2 text-sm text-muted-foreground">
                    {line.description ?? "—"}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-right font-mono text-sm text-foreground">
                    {money(line.debit)}
                    <ForeignCell line={line} />
                  </td>
                  <td className="px-3 py-2 text-right font-mono text-sm text-foreground">
                    {money(line.credit)}
                    <ForeignCell line={line} />
                  </td>
                </tr>
              ))}
            </tbody>
            {journal.totals ? (
              <tfoot>
                <tr className="border-t border-border-strong">
                  <td className="px-3 py-2" />
                  <td className="px-3 py-2 text-sm font-medium text-foreground" colSpan={2}>
                    Totals
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-right font-mono text-sm font-medium text-foreground">
                    {money(journal.totals.total_debit)}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-right font-mono text-sm font-medium text-foreground">
                    {money(journal.totals.total_credit)}
                  </td>
                </tr>
              </tfoot>
            ) : null}
          </table>
        </div>
      </div>
    </Dialog>
  );
}
