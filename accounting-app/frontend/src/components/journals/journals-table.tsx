"use client";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { formatDateOnly } from "@/lib/format/date";
import { MONEY_DISPLAY_DECIMALS, formatMoney } from "@/lib/format/money";
import type { Journal, JournalSourceValue, JournalStatusValue } from "@/lib/journals/types";

import type { JournalAction } from "./journal-confirm-dialog";

/**
 * Responsive journal list: a table on medium+ screens and stacked cards below.
 *
 * Columns follow the verified journal contract — number, date, description and
 * reference, status/source, and the entry amount derived from the loaded lines.
 * A draft that is not balanced is called out, because the backend allows an
 * unbalanced draft to exist even though it cannot be posted. Actions render only
 * when the caller has the matching permission and the journal is a draft; the
 * backend still authorises every request.
 */

type JournalRowActionsProps = {
  journal: Journal;
  canUpdate: boolean;
  canDelete: boolean;
  canPost: boolean;
  onView: (journal: Journal) => void;
  onEdit: (journal: Journal) => void;
  onAction: (action: JournalAction) => void;
};

function statusLabel(status: JournalStatusValue): string {
  return status === "POSTED" ? "Posted" : "Draft";
}

function sourceLabel(source: JournalSourceValue): string {
  const words = source.toLowerCase().split("_");
  return words.map((word) => word.charAt(0).toUpperCase() + word.slice(1)).join(" ");
}

function amount(journal: Journal): string {
  const total = journal.totals?.total_debit;
  return total ? formatMoney(total, { decimals: MONEY_DISPLAY_DECIMALS }) : "—";
}

function StatusCell({ journal }: { journal: Journal }) {
  const isPosted = journal.status === "POSTED";

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <Badge variant={isPosted ? "success" : "warning"}>{statusLabel(journal.status)}</Badge>
      {journal.source_type && journal.source_type !== "MANUAL" ? (
        <Badge variant="info">{sourceLabel(journal.source_type)}</Badge>
      ) : null}
      {journal.totals && !journal.totals.is_balanced && journal.status === "DRAFT" ? (
        <Badge variant="danger">Unbalanced</Badge>
      ) : null}
    </div>
  );
}

function JournalRowActions({
  journal,
  canUpdate,
  canDelete,
  canPost,
  onView,
  onEdit,
  onAction,
}: JournalRowActionsProps) {
  const isDraft = journal.status === "DRAFT";

  return (
    <div className="flex flex-wrap items-center justify-end gap-1.5">
      <Button size="sm" variant="secondary" onClick={() => onView(journal)}>
        View
      </Button>

      {isDraft && canUpdate ? (
        <Button size="sm" variant="secondary" onClick={() => onEdit(journal)}>
          Edit
        </Button>
      ) : null}

      {isDraft && canPost ? (
        <Button size="sm" onClick={() => onAction({ kind: "post", journal })}>
          Post
        </Button>
      ) : null}

      {isDraft && canDelete ? (
        <Button
          size="sm"
          variant="ghost"
          className="text-danger hover:bg-danger-soft hover:text-danger"
          onClick={() => onAction({ kind: "delete", journal })}
        >
          Delete
        </Button>
      ) : null}
    </div>
  );
}

export type JournalsTableProps = Omit<JournalRowActionsProps, "journal"> & {
  items: Journal[];
};

export function JournalsTable({
  items,
  canUpdate,
  canDelete,
  canPost,
  onView,
  onEdit,
  onAction,
}: JournalsTableProps) {
  return (
    <>
      <table className="hidden w-full border-collapse text-left md:table">
        <caption className="sr-only">Journals</caption>
        <thead>
          <tr className="border-b border-border">
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Number
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Date
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Description
            </th>
            <th scope="col" className="px-3 py-2 text-xs font-medium text-muted-foreground">
              Status
            </th>
            <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
              Amount
            </th>
            <th scope="col" className="px-3 py-2 text-right text-xs font-medium text-muted-foreground">
              <span className="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((journal) => (
            <tr key={journal.id} className="border-b border-border last:border-0">
              <td className="px-3 py-2 font-mono text-sm text-foreground">
                {journal.journal_number}
              </td>
              <td className="whitespace-nowrap px-3 py-2 text-sm text-foreground">
                {formatDateOnly(journal.journal_date)}
              </td>
              <td className="px-3 py-2">
                <p className="text-sm font-medium text-foreground">
                  {journal.description ?? "—"}
                </p>
                {journal.reference ? (
                  <p className="mt-0.5 max-w-md truncate text-xs text-muted-foreground">
                    Ref: {journal.reference}
                  </p>
                ) : null}
              </td>
              <td className="px-3 py-2">
                <StatusCell journal={journal} />
              </td>
              <td className="whitespace-nowrap px-3 py-2 text-right font-mono text-sm text-foreground">
                {amount(journal)}
              </td>
              <td className="px-3 py-2">
                <JournalRowActions
                  journal={journal}
                  canUpdate={canUpdate}
                  canDelete={canDelete}
                  canPost={canPost}
                  onView={onView}
                  onEdit={onEdit}
                  onAction={onAction}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <ul className="flex flex-col gap-3 md:hidden">
        {items.map((journal) => (
          <li
            key={journal.id}
            className="rounded-lg border border-border bg-surface p-4"
          >
            <div className="flex items-baseline justify-between gap-3">
              <p className="font-mono text-sm text-muted-foreground">
                {journal.journal_number}
              </p>
              <StatusCell journal={journal} />
            </div>
            <p className="mt-1 text-sm font-medium text-foreground">
              {journal.description ?? "—"}
            </p>
            <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
              <span>{formatDateOnly(journal.journal_date)}</span>
              <span aria-hidden="true" className="text-border-strong">•</span>
              <span className="font-mono">{amount(journal)}</span>
            </div>
            {journal.reference ? (
              <p className="mt-0.5 text-xs text-muted-foreground">Ref: {journal.reference}</p>
            ) : null}
            <div className="mt-3 border-t border-border pt-3">
              <JournalRowActions
                journal={journal}
                canUpdate={canUpdate}
                canDelete={canDelete}
                canPost={canPost}
                onView={onView}
                onEdit={onEdit}
                onAction={onAction}
              />
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
