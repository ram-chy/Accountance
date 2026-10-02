<?php

namespace App\Enums;

/**
 * Bank-reconciliation status.
 *
 * Three states, and the transitions between them are the entire workflow:
 *
 *   DRAFT        created, nothing cleared yet
 *   IN_PROGRESS  at least one posted line has been cleared
 *   RECONCILED   the statement and the ledger were proved to agree
 *
 * DRAFT is separated from IN_PROGRESS for a reason that is not ceremony: a
 * reconciliation with no items has not yet made any claim, so it may still have
 * its bank account and its period changed freely. Once an item exists, moving
 * the period would silently re-interpret which movements that item refers to,
 * so the record settles into IN_PROGRESS and the period is held still. A user
 * who picks the wrong statement period can delete a DRAFT and start again; the
 * cost of getting it wrong is one request, and the alternative - a period that
 * moves underneath attached evidence - is not recoverable.
 *
 * RECONCILED is immutable. The only way out of it is the explicit, separately
 * authorized reopen, which returns the record to IN_PROGRESS and never to
 * DRAFT, because an item exists and so the "nothing claimed yet" state no
 * longer describes it. This mirrors AccountingPeriod, where a reopened period
 * is OPEN rather than REOPENED: the history of having been closed lives in the
 * reopen audit columns, not in a status every reader would have to understand.
 */
enum BankReconciliationStatus: string
{
    case Draft = 'DRAFT';
    case InProgress = 'IN_PROGRESS';
    case Reconciled = 'RECONCILED';

    /**
     * Is this reconciliation still being worked on?
     *
     * True for DRAFT and IN_PROGRESS, and used to answer "may this record be
     * edited?" so the two editable states cannot drift apart from this method.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::InProgress;
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isReconciled(): bool
    {
        return $this === self::Reconciled;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
