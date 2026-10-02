<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 - one posted ledger line, marked as cleared by the bank.
 *
 * This table is the whole reconciliation layer, and it holds no accounting data
 * at all. Each row says exactly one thing: "this reconciliation considered this
 * posted journal line, and the bank confirmed it cleared." The amount is not
 * copied here - it is read from the journal line, which remains the only place a
 * monetary figure lives. The date is not copied here either; it is read from the
 * journal, and never from created_at, because a line keyed in today for last
 * month belongs to last month.
 *
 * Deliberately absent from this schema, each absence argued in the brief:
 *
 *   - `cleared_amount`  - would duplicate journal_lines.debit / credit. The
 *                         unique index below makes one line clearable at most
 *                         once per reconciliation, so the stored amount could
 *                         only ever equal the line's own amount: a second copy
 *                         free to disagree with the first, bought at the price
 *                         of one.
 *   - `journal_date`    - belongs to the journal; copying it would give the
 *                         item two dates and no way to say which one is real.
 *   - `reference`,
 *     `source_type`,
 *     `source_id`       - ditto. All reachable through journal_lines -> journals.
 *
 * There is no `cleared` column on journal_lines either, and that is the more
 * important absence. Clearing is a property of a reconciliation, not of the
 * ledger: the same posted line is legitimately cleared once per statement period
 * it appears in, and a global flag could not say which. It would also mean
 * reconciliation had written to the accounting record, which is the one thing
 * this phase must never do.
 *
 * journal_id and journal_line_id are both stored. journal_line_id alone would
 * suffice for the relationship, but the movement listing joins to this table by
 * journal_line_id and reads journal_id off the row; carrying it means that read
 * is covered by the index below rather than joined back through journal_lines
 * for every movement listed. Both are written together by
 * BankReconciliationMovementService from the one line it has already loaded, so
 * they cannot disagree.
 *
 * There are no CHECK constraints here, unlike on bank_reconciliations. Every
 * column on this table is either a foreign key, a timestamp or free text: the
 * date-order and status-enumeration rules the parent table needs have no
 * counterpart here, and a CHECK over a column that also carries a foreign key
 * with a referential action is rejected by MySQL (error 3823) in any case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Cascaded, unlike the bank_account and account references on the
             * parent. Deleting a reconciliation must delete the items that only
             * exist to describe it - they have no meaning outside the
             * reconciliation - and leaving them would orphan rows whose parent
             * no longer exists. This is reachable only through
             * BankReconciliationService::deleteDraft, which refuses anything but
             * a DRAFT, so no completed reconciliation's items can be reached.
             */
            $table->foreignId('bank_reconciliation_id')
                ->constrained('bank_reconciliations')
                ->cascadeOnDelete();

            /*
             * The accounting record this item refers to. Restricted: a posted
             * journal is the permanent record and is never deleted in this
             * system, and if one ever were the reconciliation referring to it
             * would have to be deleted deliberately rather than silently losing
             * the reference.
             */
            $table->foreignId('journal_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('journal_line_id')
                ->constrained('journal_lines')
                ->restrictOnDelete();

            /*
             * Who confirmed the clearing, and when.
             *
             * cleared_at is NOT NULL: the existence of this row *is* the claim
             * that the line cleared, so "cleared at no particular time" is not a
             * state the table should be able to represent. A nullable column
             * would let a future write path produce a cleared item with no
             * timestamp and no rule would catch it.
             *
             * cleared_by is nullable with nullOnDelete, and for a different
             * reason: an item is a historical statement that a line cleared, and
             * losing the row because the person who checked it has left the
             * company would destroy accounting evidence. The audit degrades to
             * "this user no longer exists" rather than vanishing with the row.
             */
            $table->foreignId('cleared_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('cleared_at');

            $table->text('notes')->nullable();

            $table->timestamps();

            /*
             * The rule the brief calls out as the guard against double-clearing:
             * a posted line may be cleared at most once inside one reconciliation.
             *
             * Enforced by the database rather than only by a service check,
             * because the service check is a check-then-act - it cannot see
             * another transaction's uncommitted row - and the unique index is what
             * actually settles the race between two simultaneous clear requests.
             */
            $table->unique(
                ['bank_reconciliation_id', 'journal_line_id'],
                'bank_recon_items_reconciliation_line_unique'
            );

            /*
             * The movement-eligibility lookup asks "which lines are already
             * cleared in an earlier completed reconciliation", which joins this
             * table by journal_line_id and filters the parent by status. Without
             * a leading journal_line_id that is a full scan of every item in the
             * system for every movement listed. The foreign key's own index
             * covers bank_reconciliation_id, so it is not duplicated here.
             */
            $table->index('journal_line_id', 'bank_recon_items_line_index');

            $table->index('company_id', 'bank_recon_items_company_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_items');
    }
};
