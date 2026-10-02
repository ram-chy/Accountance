<?php

namespace App\Models;

use Database\Factories\BankReconciliationItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One posted ledger line, cleared against one statement.
 *
 * The item is a relationship, not a record of money. It says which posted
 * journal line a particular reconciliation found on the bank statement, and it
 * deliberately stores nothing about that line beyond its two keys: the amount,
 * the date, the reference and the source document are all read through
 * journalLine and journal, which are the only places they exist.
 *
 * The reason is not tidiness. A copied amount is a figure with two homes, and
 * this system has spent six phases refusing to give any monetary value a second
 * home. If a copied cleared_amount could disagree with journal_lines.debit, a
 * reconciliation would report a difference that no accounting error exists to
 * explain - the worst possible failure mode for the feature whose whole job is
 * explaining differences.
 *
 * The other reason is that clearing is not a property of the ledger. The same
 * posted line may legitimately appear on several statements over several
 * periods, and each occurrence is a separate claim about the bank. That is why
 * there is no `cleared` column on journal_lines, and why the uniqueness here is
 * scoped to a single reconciliation rather than global: a global constraint
 * would be the same mistake in the other direction, silently preventing a line
 * from being reconciled a second time after the bank cleared it again.
 *
 * `company_id`, `journal_id`, `journal_line_id`, `cleared_by` and `cleared_at`
 * are all absent from $fillable: they are decided by
 * BankReconciliationMovementService from the reconciliation, the line it has
 * just validated and the authenticated user. A client submits a journal_line_id
 * and nothing else.
 */
#[Fillable([
    'notes',
])]
class BankReconciliationItem extends Model
{
    /** @use HasFactory<BankReconciliationItemFactory> */
    use HasFactory;

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * The posted line this item clears.
     *
     * The amount, direction and date all come from here rather than from this
     * table, which is the point of the model.
     */
    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }

    /**
     * Who confirmed the clearing.
     *
     * Null after the user who created it has been deleted, which is the intended
     * behaviour: the item survives because it is accounting evidence, and only
     * its attribution degrades.
     */
    public function clearer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }
}
