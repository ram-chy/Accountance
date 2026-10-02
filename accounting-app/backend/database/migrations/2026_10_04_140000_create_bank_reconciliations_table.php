<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 - one bank's statement period, reconciled against the ledger.
 *
 * The central fact about this table is what it does NOT store. Every monetary
 * column below is an external statement figure the user read off a bank
 * document; not one of them is an accounting balance:
 *
 *   - no ledger_opening_balance column
 *   - no ledger_closing_balance column
 *   - no difference column
 *   - no cleared/uncleared totals
 *
 * A ledger balance is a question about posted journal lines, asked of
 * LedgerService, and the answer is the same question for a cash account and a
 * bank account alike. Caching one here would be a second source of truth that
 * could disagree with the journal lines it summarises - and reconciliation is
 * precisely the feature where a stale balance would be most damaging, because a
 * difference computed from a stale balance looks like a real bank discrepancy
 * and sends someone looking for an accounting error that does not exist.
 *
 * The difference is therefore always derived from current reconciliation facts,
 * never read from a column. See BankReconciliationService::summary() for the
 * arithmetic and the reasoning behind each term.
 *
 * `account_id` is stored alongside `bank_account_id` even though the account is
 * reachable through the bank account. It is denormalisation for a reason rather
 * than an oversight: bank_reconciliation_items and the movement listing both
 * filter posted journal lines by account_id, and a join back through
 * bank_accounts on every one of those queries would put a second table in the
 * hot path of the only reporting this phase performs. The pair is written
 * together by BankReconciliationService and never by a request body, so the two
 * columns cannot drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * The bank this reconciliation is for. Restricted rather than
             * cascaded: deleting bank details for an account that has been
             * reconciled against would destroy the evidence that the account was
             * ever reconciled, and CashBankAccountService already refuses to
             * remove bank details from an account with accounting history.
             */
            $table->foreignId('bank_account_id')
                ->constrained('bank_accounts')
                ->restrictOnDelete();

            /*
             * The ledger account the statement is reconciled against - the same
             * account this bank_account describes. See the class docblock.
             */
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            /*
             * The statement period, inclusive at both ends, on exactly the terms
             * accounting_periods and financial_years already use. A journal dated
             * 2026-10-01 or 2026-10-31 falls in a reconciliation running
             * 2026-10-01 to 2026-10-31. The pairing is enforced by the CHECK
             * constraint below and re-applied in the service, which is what has
             * to hold for a stored row and not only for a validated payload.
             */
            $table->date('from_date');
            $table->date('to_date');

            /*
             * The two figures the user read off the bank statement. These are
             * external facts, not accounting entries: they are never posted, they
             * never create a journal, and no code path derives them from anything
             * in this system.
             *
             * Signed rather than positive-only, because a bank account can be
             * overdrawn and a statement will say so. Restricting these to >= 0
             * would have rejected a true statement.
             *
             * DECIMAL(20,4) to match Money and every other monetary column in
             * this schema.
             */
            $table->decimal('statement_opening_balance', 20, 4);
            $table->decimal('statement_closing_balance', 20, 4);

            /*
             * DRAFT, IN_PROGRESS or RECONCILED. Moves only through
             * BankReconciliationCompletionService and
             * BankReconciliationReopenService, each of which holds the row while
             * it changes, so it is absent from the model's $fillable.
             */
            $table->string('status', 20)->default('DRAFT');

            /*
             * Audit for the reconciliation itself and for its two privileged
             * transitions. created_by records who set the reconciliation up;
             * completed_by/completed_at record the act that asserts the statement
             * and the ledger agree; reopened_by/reopened_at record the
             * separately-authorized undoing of that assertion.
             *
             * All three user references are nullOnDelete rather than
             * restrictOnDelete. A reconciliation is a historical statement about
             * a past period, and deleting a user who happened to be the one who
             * signed it must not delete the record - the same reasoning Phase 8
             * applied to financial_years. The audit trail degrades to "this user
             * no longer exists" rather than disappearing with the row.
             */
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('completed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('completed_at')->nullable();

            $table->foreignId('reopened_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reopened_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            /*
             * Indexes follow the three questions this table is actually asked.
             *
             *  - (bank_account_id, status) answers "what is this bank's current
             *    reconciliation state" and drives the movement-eligibility
             *    lookup, which asks which earlier reconciliations for this account
             *    are RECONCILED.
             *  - (company_id, status) serves the listing's status filter.
             *  - (bank_account_id, to_date) answers "which reconciliation
             *    immediately precedes this one", the previous-reconciled-closing
             *    lookup in section 28 of the brief - an ordering scan, and the
             *    only one here that is not a filter.
             *
             * Named explicitly because Laravel's generated names exceed MySQL's
             * 64-character identifier limit for these column combinations.
             */
            $table->index(['company_id', 'status'], 'bank_recon_company_status_index');
            $table->index(['bank_account_id', 'status'], 'bank_recon_account_status_index');
            $table->index(['bank_account_id', 'to_date'], 'bank_recon_account_to_date_index');
        });

        SchemaCheck::add(
            'bank_reconciliations',
            'to_date >= from_date',
            'bank_reconciliations_date_order_check'
        );

        SchemaCheck::add(
            'bank_reconciliations',
            "status in ('DRAFT','IN_PROGRESS','RECONCILED')",
            'bank_reconciliations_status_check'
        );

        /*
         * Deliberately no CHECK tying status = 'RECONCILED' to completed_by /
         * completed_at being set. MySQL refuses a CHECK over a column that also
         * carries a foreign key with a referential action (error 3823), and
         * completed_by is ON DELETE SET NULL precisely so deleting a user does not
         * take the reconciliation with it. The pairing is therefore enforced in
         * BankReconciliationCompletionService, which is also the only writer of
         * `status`. Same reasoning and same trade-off as financial_years_status.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
